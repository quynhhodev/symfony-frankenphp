# Monitoring: Prometheus, Grafana, Alertmanager, Loki, Alloy

> Soạn 01/11/2026 cho cụm multipass (k8s v1.35.9, ArgoCD v3.5.1).

| Thành phần | Chart (ghim trong devops/argocd/) | Values | Làm gì |
|---|---|---|---|
| Prometheus, Grafana, Alertmanager, node-exporter, kube-state-metrics | `kube-prometheus-stack` 92.16.2 | `monitoring/kube-prometheus-stack.values.yaml` | Metrics, dashboard, cảnh báo |
| Loki | `loki` 7.5.0 | `monitoring/loki.values.yaml` | Lưu log tập trung |
| Alloy | `alloy` 1.15.0 | `monitoring/alloy.values.yaml` | Gom log mọi pod gửi về Loki |
| Phần riêng của dự án | Kustomize `monitoring/extras` | | Secret admin Grafana, sau này dashboard và luật cảnh báo |

Cả 4 là ArgoCD Application: đổi cấu hình là sửa file trong git rồi merge. Chart lấy từ Helm
repo, values lấy từ git (Application nhiều nguồn, `$values`).

## Tài nguyên

`helm template` với values ở đây, cộng requests mọi container: khoảng 370m CPU và 1.1Gi RAM,
cộng config-reloader mà operator chèn vào Prometheus/Alertmanager. Node worker có khoảng
5.7Gi. Mọi container đều khai limit memory. Metrics và log giữ 7 ngày, mỗi thứ một PVC 5Gi
(local-path).

Mặc định của chart Loki là cho cụm thật (9 pod, memcached xin 8Gi, S3): riêng mặc định đã
không có chỗ chạy. Đã chuyển sang một tiến trình, lưu ổ local, tắt cache/canary/gateway.

## Bootstrap (một lần), THỨ TỰ quan trọng

```bash
K="kubectl --context multipass"
# 1. extras TRƯỚC: Grafana chờ Secret grafana-admin, thiếu là pod Grafana không lên.
$K apply -n argocd -f devops/argocd/monitoring-extras.yaml
# 2. kube-prometheus-stack: cài CRD (ServiceMonitor, PrometheusRule...) mà các bước sau cần.
$K apply -n argocd -f devops/argocd/monitoring-kube-prometheus-stack.yaml
# 3. Loki, rồi Alloy (Alloy đẩy log vào Loki).
$K apply -n argocd -f devops/argocd/monitoring-loki.yaml
$K apply -n argocd -f devops/argocd/monitoring-alloy.yaml
```

### Traefik: bật ServiceMonitor (sau bước 2, cần CRD ServiceMonitor)

Traefik cài bằng Helm tay (devops/MIGRATION-traefik.md), không qua ArgoCD. `--reuse-values`
giữ nguyên NodePort 32723 và ingressEndpoint:

```bash
helm --kube-context multipass upgrade traefik traefik/traefik --version 41.7.1 -n traefik \
  --reuse-values \
  --set metrics.prometheus.service.enabled=true \
  --set metrics.prometheus.serviceMonitor.enabled=true
```

Thêm Service `traefik-metrics` và ServiceMonitor, và chart BỎ 3 annotation `prometheus.io/*`
khỏi pod template (không cần nữa khi đã có ServiceMonitor), nên **Traefik rollout lại một
lần**. Chạy 01/11/2026: 1/362 request lỗi đúng lúc pod Traefik cũ tắt (Traefik không có
preStop, cùng kiểu lỗi đã sửa cho frankenphp bằng `preStop` sleep).

Kiểm trước khi chạy: so `helm get manifest` với `helm template ... --api-versions
monitoring.coreos.com/v1` (thiếu cờ này thì template báo chưa có CRD ServiceMonitor).

## Vào Grafana

```bash
echo "10.76.44.99 grafana.local" | sudo tee -a /etc/hosts    # IP node worker
# http://grafana.local:32723  (32723 = NodePort http của Traefik), user `admin`
kubectl --context multipass -n monitoring get secret grafana-admin \
  -o jsonpath='{.data.admin-password}' | base64 -d
```

Đổi password admin (niêm phong lại, scope strict cho đúng monitoring/grafana-admin):

```bash
GRAFANA_PASSWORD=$(openssl rand -hex 24) python3 -c '
import json, os
print(json.dumps({"apiVersion": "v1", "kind": "Secret", "type": "Opaque",
  "metadata": {"name": "grafana-admin", "namespace": "monitoring"},
  "stringData": {"admin-user": "admin", "admin-password": os.environ["GRAFANA_PASSWORD"]}}))' \
| kubeseal --cert devops/overlays/multipass/sealed-secrets-cert.pem --scope strict --format yaml
```

Dán đầu ra vào `monitoring/extras/grafana-admin.sealed.yaml` (giữ khối comment đầu file).
Grafana chỉ đọc password admin ở LẦN KHỞI TẠO ĐẦU: đổi sau đó phải đổi cả trong Grafana
(`grafana cli admin reset-admin-password`) hoặc xoá pod khi chưa có dữ liệu cần giữ.

## Metrics của app (qua Traefik)

Prometheus ghi đè label `service` của Traefik bằng tên Service k8s (`traefik-metrics`) và
chuyển giá trị gốc sang **`exported_service`**. Service của app:

```
sum(rate(traefik_service_requests_total{exported_service="frankenphp-frankenphp-80@kubernetes"}[5m])) by (code)
```

## Cảnh báo đang có sẵn

Lúc cài xong (01/11/2026): 19 target UP, 0 DOWN. Đang firing: `Watchdog` (luôn firing, để
biết đường cảnh báo còn sống) và `KubeCPUOvercommit` (warning: tổng CPU requests không còn
vừa nếu mất một node; với cụm 2 node, một control plane, thì điều này luôn đúng). Cần chỉnh
hoặc tắt luật này khi thêm luật riêng của app.

## Đọc log

Grafana → Explore → datasource **Loki**:

```
{namespace="frankenphp", container="frankenphp"} |= "error"
{namespace="frankenphp", container="worker"}
```

Label có: `namespace`, `container`, `app`, `pod`. Alloy đọc log qua API của k8s
(`loki.source.kubernetes`), không mount thư mục log của node, nên chạy một Deployment, không
cần quyền hostPath.

## Những gì CHƯA có, và vì sao

- **Control plane** (controller-manager, scheduler, etcd, kube-proxy): kubeadm chỉ mở
  metrics ở 127.0.0.1, Prometheus không với tới. Đã tắt cả thu thập lẫn luật cảnh báo
  tương ứng, không thì báo TargetDown vĩnh viễn.
- **Cảnh báo PVC sắp đầy**: kubelet không báo dung lượng cho volume local-path (hostPath),
  nên `KubePersistentVolumeFillingUp` không bao giờ chạy. Thay vào đó là cảnh báo đĩa của
  node (node-exporter).
- **Metrics bên trong app**: endpoint metrics của Caddy/FrankenPHP chỉ mở ở 127.0.0.1. Request,
  lỗi, độ trễ của app lấy từ Traefik.
- **Kênh nhận cảnh báo**: chưa gửi đi đâu; xem trong Grafana (Alerting) hoặc UI Alertmanager.
- **Namespace `monitoring` không enforce Pod Security**: node-exporter cần hostNetwork,
  hostPID, hostPath. Chỉ `warn`/`audit` baseline.
