# HPA và PDB cho frankenphp

> Soạn 25/10/2026. Bước 1 và 2 đã chạy trên cụm multipass (k8s v1.35.9) trước khi merge.

## Có gì

- `autoscaling/frankenphp-hpa.yaml`: 2–5 pod, thêm pod khi CPU trung bình vượt 70% của
  `requests.cpu` (100m).
- `autoscaling/frankenphp-pdb.yaml`: drain node luôn chừa lại ít nhất 1 pod.
- `deployment/frankenphp-deployment.yaml` bỏ `replicas`, để HPA là chủ duy nhất của số pod.

## THỨ TỰ: làm 2 bước dưới TRƯỚC khi merge

### 1. Cài metrics-server

HPA đọc CPU từ metrics-server. Thiếu nó thì HPA kẹt ở `<unknown>/70%` và không bao giờ scale.

```bash
helm repo add metrics-server https://kubernetes-sigs.github.io/metrics-server/
helm repo update metrics-server
# Cụm kubeadm: kubelet dùng cert tự ký, thiếu --kubelet-insecure-tls thì
# metrics-server không scrape được node nào.
helm --kube-context multipass install metrics-server metrics-server/metrics-server \
  --version 3.14.0 --namespace kube-system \
  --set 'args={--kubelet-insecure-tls}' --wait

kubectl --context multipass top nodes   # phải ra số liệu
```

VPS chạy k3s thì bỏ qua bước này: k3s có sẵn metrics-server.

### 2. Bàn giao quyền sở hữu `replicas`

ArgoCD sync bằng Server-Side Apply, và đang là manager DUY NHẤT sở hữu `spec.replicas`.
Bỏ trường đó khỏi git thì API server coi như ArgoCD "nhả" nó và đặt về mặc định **1**:
một pod bị xoá ngay, vài giây sau HPA mới kéo lên lại 2.

Cho một manager khác cùng sở hữu `replicas` trước, thì ArgoCD nhả ra cũng không reset:

```bash
kubectl --context multipass apply --server-side --field-manager=handover-to-hpa \
  --validate=false -f - <<'EOF'
apiVersion: apps/v1
kind: Deployment
metadata:
  name: frankenphp
spec:
  replicas: 2
EOF
```

Kiểm tra trước khi merge, phải in ra `2`:

```bash
kubectl --context multipass apply --server-side --field-manager=argocd-controller \
  --dry-run=server -f devops/base/deployment/frankenphp-deployment.yaml \
  -o jsonpath='{.spec.replicas}{"\n"}'
```

Lần đầu HPA scale, nó ghi `replicas` qua subresource `scale` và thành chủ của trường này.

## Sau khi merge: kiểm tra

```bash
kubectl --context multipass get hpa frankenphp   # TARGETS dạng 3%/70%, không phải <unknown>
kubectl --context multipass get pdb frankenphp   # ALLOWED DISRUPTIONS = 1
kubectl --context multipass get deploy frankenphp
```

## Thử tải

Terminal 1:

```bash
kubectl --context multipass get hpa frankenphp -w
```

Terminal 2: 6 vòng lặp gọi song song, tự dừng sau 5 phút.

```bash
kubectl --context multipass run load --rm -i --image=busybox:1.36 --restart=Never -- \
  sh -c 'for w in 1 2 3 4 5 6; do (while true; do wget -q -O /dev/null http://frankenphp/health/live; done) & done; sleep 300'
```

Mong đợi: TARGETS vượt 70% sau khoảng 30 giây, REPLICAS tăng dần (tối đa 5). Hết tải thì
khoảng 5 phút sau mới giảm về 2. Đó là cửa sổ ổn định mặc định của scale down, không phải lỗi.

Không thử PDB bằng `kubectl drain --dry-run`: dry-run không trừ ngân sách của PDB, nên
cả 2 pod đều báo "evicted" và trông như PDB không có tác dụng. Cụm chỉ có một node
worker, drain thật thì mọi pod đều không có chỗ chạy, nên chỉ cần xem
`ALLOWED DISRUPTIONS` của `kubectl get pdb`.
