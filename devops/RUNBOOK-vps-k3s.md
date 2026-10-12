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

### Manifest hiện tại chạy được ngay, không sửa gì

Đã đối chiếu:

- `storageClassName: local-path` ở cả hai StatefulSet → **trùng đúng tên** provisioner k3s cho sẵn
- Không có `nodeSelector`, `affinity`, hay `topologySpreadConstraints` → một node chạy được
- Cả 3 ingress khai `ingressClassName: traefik` → **trùng đúng tên** IngressClass k3s cho sẵn,
  CRD `Middleware` (`traefik.io/v1alpha1`) của rate limit cũng có sẵn
- `replicas: 2` của frankenphp vẫn chạy trên một node, nhưng **không còn ý nghĩa HA** — hai pod chết cùng lúc khi VPS chết

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

# 3) giao repo cho ArgoCD
git clone https://github.com/quynhhodev/symfony-frankenphp.git
kubectl apply -n argocd -f symfony-frankenphp/devops/argocd/argocd-application.yaml
```

**Sau bước 3 là hết phần tay.** ArgoCD kéo `main` và tự dựng postgres, rabbitmq,
frankenphp, messenger-worker, Reloader, ingress, rồi chạy Job migration PostSync.

> `devops/` có `recurse: true` nhưng ArgoCD chỉ đọc `.yaml`/`.yml`/`.json` — file `.md`
> này bị bỏ qua. Kiểm ở lần sync đầu: `Application` không được liệt kê resource lạ nào.

---

## 3. Sửa cái duy nhất phải sửa: host của ingress

`frankenphp-ingress.yaml` đang khai `host: frankenphp.local`. Đổi sang domain thật rồi
commit — ArgoCD tự rollout:

```yaml
- host: app.<domain>
```

Đây là **thay đổi manifest duy nhất** mà việc chuyển sang VPS bắt buộc.

---

## 4. BẮT BUỘC làm trước khi trỏ DNS

> Mọi quyết định "để password plaintext là có chủ đích" trong repo này đều gắn điều kiện
> *cluster local, không ra internet*. **VPS phá vỡ đúng điều kiện đó.** Bốn việc dưới đây
> không phải khuyến nghị — không làm thì đừng mở ra internet.

**4.1 — Xoay mọi password sang giá trị thật, đưa vào Sealed Secrets**

Đang lộ công khai trên GitHub: `local-dev-only` (RabbitMQ) và `mysecretpassword` (postgres).

```bash
helm install sealed-secrets sealed-secrets/sealed-secrets -n kube-system
# rồi kubeseal từng secret, thay devops/secret/secret.yaml bằng bản đã seal
```

Nhớ sửa cả `definitions.json` trong `devops/configmap/rabbitmq-definitions.yaml` cho khớp
— guard ở `test.yml` sẽ chặn nếu lệch.

**4.2 — Gỡ RabbitMQ management UI khỏi internet**

`devops/ingress/rabbitmq-ingress.yaml` mở UI quản trị đầy đủ. Trên VPS: **xoá file đó**,
vào bằng port-forward qua SSH khi cần:

```bash
ssh -L 15672:localhost:15672 user@vps
kubectl -n default port-forward svc/rabbitmq 15672:15672
```

**4.3 — Đừng apply `argocd-ingress.yaml`**

File đó bật `server.insecure: "true"` (HTTP trần). Ai vào được ArgoCD UI là điều khiển
được toàn bộ deploy. Trên VPS dùng port-forward, cùng cách như 4.2.

**4.4 — TLS cho app**

```bash
kubectl apply -f https://github.com/cert-manager/cert-manager/releases/download/v1.19.1/cert-manager.yaml
```

Rồi tạo `ClusterIssuer` Let's Encrypt và thêm khối `tls:` vào `frankenphp-ingress.yaml`.
Hiện `devops/` **không có một dòng `tls:` nào**.

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
kubectl get pvc                                  # postgres + rabbitmq đều Bound
curl -I https://app.<domain>/env/data            # HTTP 200, có TLS
kubectl -n default exec sts/rabbitmq -- rabbitmqctl list_queues name messages
kubectl -n default logs deploy/messenger-worker --tail=20   # thấy fan-out 2 handler
```

**Đừng hoảng** khi cột `consumers` của queue bằng 0 — Symfony AMQP transport dùng
`basic_get`, không đăng ký consumer thường trú. Tín hiệu đúng là `messages` rút về 0.

---

## 7. Gỡ sạch

```bash
/usr/local/bin/k3s-uninstall.sh
```

Xoá luôn cả dữ liệu trong `local-path`. Chắc chắn đã có backup trước khi chạy.
