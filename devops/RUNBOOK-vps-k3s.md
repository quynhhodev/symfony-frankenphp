# Runbook: đưa dự án lên một VPS bằng k3s

> Soạn 28/08/2026. Mọi version dưới đây đã kiểm tại thời điểm đó — kiểm lại trước khi chạy.

## 0. Vì sao k3s chứ không phải kubeadm

Cluster multipass hiện tại (kubeadm) cần **6 bước** bootstrap. k3s gói sẵn 4 trong số đó:

| Thành phần | kubeadm | k3s |
|---|---|---|
| containerd | tự cài | có sẵn |
| CNI (flannel) | tự cài | có sẵn |
| CoreDNS | có sẵn | có sẵn |
| StorageClass | tự cài local-path-provisioner | **có sẵn, tên đúng là `local-path`** |
| LoadBalancer | không có | có sẵn (klipper-lb) |
| Ingress controller | tự cài (Traefik, xem `MIGRATION-traefik.md`) | **có sẵn Traefik, dùng luôn** |

Còn **3 bước**.

### Base chạy được trên k3s, chỉ overlay `vps` vá phần khác

VPS dùng overlay `devops/overlays/vps`: chung `devops/base` với cụm multipass, chỉ khác
host của ingress (mục 3) và bỏ ingress RabbitMQ (mục 4.2). Đã đối chiếu base với k3s:

- `storageClassName: local-path` ở cả hai StatefulSet → **trùng đúng tên** provisioner k3s cho sẵn
- Không có `nodeSelector`, `affinity`, hay `topologySpreadConstraints` → một node chạy được
- Cả 3 ingress khai `ingressClassName: traefik` → **trùng đúng tên** IngressClass k3s cho sẵn,
  CRD `Middleware` (`traefik.io/v1alpha1`) của rate limit cũng có sẵn
- HPA giữ tối thiểu 2 pod frankenphp, vẫn chạy trên một node, nhưng **không còn ý nghĩa HA** — mọi pod chết cùng lúc khi VPS chết.
  k3s có sẵn metrics-server nên HPA chạy ngay (xem `devops/HPA.md`)

Trước đây runbook tắt Traefik để dùng ingress-nginx. ingress-nginx đã ngừng bảo trì từ
3/2026 (không còn vá bảo mật), nên giờ manifest chuyển sang Traefik và dùng luôn bản k3s cho sẵn.

---

## 1. Chuẩn bị VPS

**Kích thước.** Requests đã khai trong cluster hiện tại là ~1.9Gi, cộng k3s server và 7 pod ArgoCD:

- **8 GB RAM / 4 vCPU** — thoải mái
- 4 GB — chạy được nhưng rất chật, ArgoCD sẽ là thứ bị OOM trước

**Hệ điều hành.** Ubuntu 22.04 hoặc 24.04 LTS.

**DNS.** Trỏ A record về IP VPS — **chỉ cho app**:

```
app.<domain>      A   <IP VPS>
```

Cố tình KHÔNG tạo record cho argocd và rabbitmq. Xem mục 4.

**Firewall.** Chỉ mở 22, 80, 443. **Đóng 6443** (Kubernetes API) với mọi IP trừ IP của anh:

```bash
ufw default deny incoming && ufw allow 22,80,443/tcp && ufw enable
```

---

## 2. Bootstrap — 3 bước

```bash
# 1) k3s, GIỮ Traefik mặc định. klipper-lb bind Service LoadBalancer của Traefik
#    thẳng :80/:443 lên host, nên không có NodePort 32723 như ở multipass.
curl -sfL https://get.k3s.io | INSTALL_K3S_VERSION="v1.36.3+k3s1" sh -s - \
  --write-kubeconfig-mode=644

export KUBECONFIG=/etc/rancher/k3s/k3s.yaml
kubectl get nodes          # phải Ready trước khi đi tiếp
kubectl get ingressclass   # phải có `traefik`

# 2) ArgoCD — cùng version đang chạy ở multipass
kubectl create namespace argocd
kubectl apply -n argocd -f https://raw.githubusercontent.com/argoproj/argo-cd/v3.5.1/manifests/install.yaml
kubectl -n argocd rollout status deploy/argocd-server --timeout=300s

# 3) giao repo cho ArgoCD. File trong repo trỏ overlay multipass, nên đổi sang
#    overlay vps khi apply. Quên đổi thì VPS dựng cả ingress RabbitMQ (mục 4.2).
git clone https://github.com/quynhhodev/symfony-frankenphp.git
sed 's#devops/overlays/multipass#devops/overlays/vps#' \
  symfony-frankenphp/devops/argocd/argocd-application.yaml | kubectl apply -n argocd -f -
kubectl -n argocd get application frankenphp -o jsonpath='{.spec.source.path}'   # phải in devops/overlays/vps
```

**Sau bước 3 là hết phần tay.** ArgoCD kéo `main` và tự dựng postgres, rabbitmq,
frankenphp, messenger-worker, Reloader, ingress, rồi chạy Job migration PostSync.

> Xem trước đúng những gì ArgoCD sẽ dựng: `kubectl kustomize devops/overlays/vps`.

---

## 3. Sửa cái duy nhất phải sửa: host của ingress

`devops/overlays/vps/kustomization.yaml` đang vá host thành `app.CHANGE-ME.invalid`,
một domain cố tình không bao giờ phân giải được. Đổi sang domain thật rồi commit, ArgoCD
tự rollout:

```yaml
        value: app.<domain>
```

Chỉ sửa trong overlay, KHÔNG sửa `frankenphp-ingress.yaml` của base: base vẫn là
`frankenphp.local` cho cụm multipass. Đây là **thay đổi manifest duy nhất** mà việc chuyển
sang VPS bắt buộc.

---

## 4. BẮT BUỘC làm trước khi trỏ DNS

> Mọi quyết định "để password plaintext là có chủ đích" trong repo này đều gắn điều kiện
> *cluster local, không ra internet*. **VPS phá vỡ đúng điều kiện đó.** Bốn việc dưới đây
> không phải khuyến nghị — không làm thì đừng mở ra internet.

**4.1 — Niêm phong password riêng của VPS bằng Sealed Secrets**

Base không còn Secret nào; cụm multipass đã dùng Sealed Secrets (xem `devops/SEALED-SECRETS.md`).
Bản niêm phong của multipass KHÔNG mở được trên VPS (mỗi controller một khóa), nên VPS phải:
cài controller, sao lưu khóa của VPS, `--fetch-cert` vào `devops/overlays/vps/`, rồi
`devops/scripts/seal-secrets.sh seal vps` với password MỚI, khác multipass. Thiếu bước này
thì pod đứng `CreateContainerConfigError` vì không có `frankenphp-secret`.

**4.2 — Gỡ RabbitMQ management UI khỏi internet**

`devops/base/ingress/rabbitmq-ingress.yaml` mở UI quản trị đầy đủ. **Đã xử lý**: overlay
`vps` bỏ ingress này (`$patch: delete`), cụm multipass vẫn giữ. Vào bằng port-forward qua
SSH khi cần:

```bash
ssh -L 15672:localhost:15672 user@vps
kubectl -n frankenphp port-forward svc/rabbitmq 15672:15672
```

**4.3 — Đừng apply `argocd-ingress.yaml`**

File đó bật `server.insecure: "true"` (HTTP trần). Ai vào được ArgoCD UI là điều khiển
được toàn bộ deploy. Trên VPS dùng port-forward, cùng cách như 4.2.

**4.4 — TLS cho app**

```bash
kubectl apply -f https://github.com/cert-manager/cert-manager/releases/download/v1.19.1/cert-manager.yaml
```

Rồi tạo `ClusterIssuer` Let's Encrypt và thêm khối `tls:` cho `frankenphp-ingress` bằng
một patch trong `devops/overlays/vps` (cụm multipass không cần TLS). Hiện `devops/`
**không có một dòng `tls:` nào**.

---

## 5. Backup — rủi ro lớn nhất, lớn hơn cả password

`local-path` ghi thẳng vào đĩa của node. Một VPS = một đĩa, **không snapshot, không replica**.
VPS chết là mất sạch postgres.

CronJob `pg_dump` đẩy ra ngoài VPS (S3/B2/rsync), **và phải thử restore ít nhất một lần** —
backup chưa từng restore thì chưa phải backup.

---

## 6. Checklist nghiệm thu

```bash
kubectl -n argocd get application frankenphp     # Synced/Healthy
kubectl get pods -A | grep -v Running            # chỉ còn Job Completed
kubectl -n frankenphp get pvc                    # postgres + rabbitmq đều Bound
curl -I https://app.<domain>/env/data            # HTTP 200, có TLS
kubectl -n frankenphp exec sts/rabbitmq -- rabbitmqctl list_queues name messages
kubectl -n frankenphp logs deploy/messenger-worker --tail=20   # thấy fan-out 2 handler
```

**Đừng hoảng** khi cột `consumers` của queue bằng 0 — Symfony AMQP transport dùng
`basic_get`, không đăng ký consumer thường trú. Tín hiệu đúng là `messages` rút về 0.

---

## 7. Gỡ sạch

```bash
/usr/local/bin/k3s-uninstall.sh
```

Xoá luôn cả dữ liệu trong `local-path`. Chắc chắn đã có backup trước khi chạy.
