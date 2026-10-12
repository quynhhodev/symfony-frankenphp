# Chuyển cụm multipass từ ingress-nginx sang Traefik

> Soạn 12/10/2026. Lệnh helm bên dưới đã chạy thử trên k3d (k3s v1.33.6) với chart
> `traefik/traefik` 41.7.1 (Traefik v3.7.14): route, rate limit và in-flight đều trả đúng.
> Chưa chạy trên chính cụm multipass.

## Vì sao

ingress-nginx đã ngừng bảo trì từ 3/2026: không còn bản release, không còn vá bảo mật.
Manifest trong `devops/` giờ khai `ingressClassName: traefik`, và rate limit chuyển từ
annotation `nginx.ingress.kubernetes.io/*` sang CRD `Middleware` của Traefik
(`devops/ingress/frankenphp-middleware.yaml`).

VPS chạy k3s thì không cần file này: k3s có sẵn Traefik, xem `RUNBOOK-vps-k3s.md`.

## THỨ TỰ: cài Traefik TRƯỚC khi merge

ArgoCD tự sync `main`. Merge khi cụm chưa có Traefik thì:

- `Middleware` sync lỗi vì CRD `traefik.io/v1alpha1` chưa tồn tại
- Ingress class `traefik` không có controller nào nhận, app tối đen

Traefik giữ lại NodePort **32723** của ingress-nginx, nên URL và `/etc/hosts` không đổi.
Đổi lại, phải gỡ ingress-nginx trước để nhả cổng: **app mất truy cập từ bước 1 tới hết
bước 3**, vài phút. Chấp nhận được vì đây là cụm dev.

## Các bước

```bash
# 1) Gỡ ingress-nginx để nhả NodePort 32723. Xem nó được cài kiểu nào trước:
helm --kube-context multipass list -A | grep ingress-nginx
#    cài bằng helm:
helm --kube-context multipass -n ingress-nginx uninstall ingress-nginx
#    hoặc cài bằng manifest: kubectl --context multipass delete ns ingress-nginx

# 2) Cài Traefik. Chart mặc định tạo Service LoadBalancer, multipass không có LB nên
#    phải ép NodePort. Key đúng là `service.spec.type`, `service.type` bị bỏ qua êm.
helm repo add traefik https://traefik.github.io/charts && helm repo update traefik
helm --kube-context multipass install traefik traefik/traefik --version 41.7.1 \
  --namespace traefik --create-namespace \
  --set service.spec.type=NodePort \
  --set ports.web.nodePort=32723 \
  --set ports.websecure.nodePort=32724 \
  --wait

kubectl --context multipass get ingressclass          # phải có `traefik`
kubectl --context multipass -n traefik get svc        # 80:32723/TCP

# 3) Merge PR vào main, chờ ArgoCD sync frankenphp-ingress, rabbitmq-ingress và 2 Middleware.

# 4) argocd-ingress.yaml là file bootstrap, ArgoCD KHÔNG quản. Apply lại tay:
kubectl --context multipass apply -f devops/argocd/argocd-ingress.yaml
```

## Kiểm tra

```bash
# app lên lại (IP nào của node cũng được)
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: frankenphp.local' http://10.62.164.198:32723/health/live

# rate limit: khoảng 50 cái 200 rồi tới 429. Dùng /sent/message vì route này không ghi DB.
for i in $(seq 70); do curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: frankenphp.local' http://10.62.164.198:32723/sent/message; done | sort | uniq -c

# Middleware sai tên/namespace thì Traefik bỏ route và chỉ báo trong log.
# Một dòng "does not exist" đúng lúc vừa sync là bình thường (Ingress nạp trước Middleware);
# lặp lại liên tục mới là lỗi thật.
kubectl --context multipass -n traefik logs deploy/traefik | grep 'does not exist'
```

## Quay lại ingress-nginx

```bash
helm --kube-context multipass -n traefik uninstall traefik
# cài lại ingress-nginx như lúc đầu, rồi revert PR trên main
```
