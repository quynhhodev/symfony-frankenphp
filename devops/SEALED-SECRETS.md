# Sealed Secrets

> Soạn 31/10/2026 cho cụm multipass (k8s v1.35.9, ArgoCD v3.5.1, controller v0.41.2).

Repo public, nên Secret chỉ được vào git dưới dạng `SealedSecret`: mã hóa bằng khóa CÔNG
KHAI của controller, chỉ controller trong cụm (giữ khóa BÍ MẬT) mở ra thành `Secret`
thường. App vẫn đọc Secret `frankenphp-secret` như cũ, không đổi gì.

| File | Vai trò |
|---|---|
| `devops/scripts/seal-secrets.sh` | NƠI DUY NHẤT sinh Secret. Mỗi password nhập một lần, dựng ra mọi chỗ dùng nó |
| `devops/overlays/<cụm>/sealed-secrets-cert.pem` | Khóa công khai của controller trên cụm đó (công khai được) |
| `devops/overlays/<cụm>/sealed-secrets.yaml` | 2 SealedSecret: `frankenphp-secret`, `rabbitmq-definitions` |

Bản niêm phong gắn với **cụm** (mỗi controller một khóa) và với **namespace/tên**
(scope `strict`): đem sang cụm khác hay namespace khác là không mở được. Vì vậy
SealedSecret nằm trong overlay, không ở base.

## Cài controller (một lần cho mỗi cụm)

```bash
helm repo add sealed-secrets https://bitnami.github.io/sealed-secrets   # repo đã dời từ bitnami-labs
helm --kube-context multipass install sealed-secrets sealed-secrets/sealed-secrets --version 2.22.0 \
  --namespace kube-system \
  --set-string fullnameOverride=sealed-secrets-controller \
  --set-string keyrenewperiod=0
```

- `fullnameOverride`: `kubeseal` mặc định tìm controller tên `sealed-secrets-controller`.
- `keyrenewperiod=0`: KHÔNG tự sinh khóa mới mỗi 30 ngày. Một khóa, sao lưu một lần.
  Muốn đổi khóa thì làm tay (mục "Đổi khóa niêm phong").

### Sao lưu khóa bí mật: BẮT BUỘC, ngay sau khi cài

Mất cụm mà không có bản này thì mọi SealedSecret trong git thành rác.

```bash
umask 077; KB=~/backups/sealed-secrets-key/$(date +%F)-multipass; mkdir -p "$KB"
kubectl --context multipass -n kube-system get secret \
  -l sealedsecrets.bitnami.com/sealed-secrets-key -o yaml > "$KB/sealed-secrets-key.yaml"
```

Cất thêm một bản vào trình quản lý mật khẩu. KHÔNG bao giờ để vào git: ai có file này là
mở được mọi bản niêm phong, kể cả bản cũ trong lịch sử git.

Dựng lại cụm: apply file này vào `kube-system` TRƯỚC khi cài controller, controller sẽ
dùng lại khóa cũ.

### Lấy khóa công khai vào repo

```bash
kubeseal --context multipass --controller-namespace kube-system \
  --controller-name sealed-secrets-controller --fetch-cert > devops/overlays/multipass/sealed-secrets-cert.pem
```

## Niêm phong

```bash
POSTGRES_PASSWORD=$(openssl rand -hex 24) RABBITMQ_PASSWORD=$(openssl rand -hex 24) \
  devops/scripts/seal-secrets.sh seal multipass
```

Thêm `FACEBOOK_ACCESS_TOKEN=...` nếu cần token Facebook. KHÔNG `kubectl patch` Secret
bằng tay: controller ghi đè Secret và xóa mọi key không có trong bản niêm phong.

Bản niêm phong là một chiều: chính bạn cũng không mở lại được file trong git. Xem giá
trị thì đọc Secret đã giải mã trên cụm.

## Đổi password trên hệ thống đang chạy

Niêm phong password mới như trên, rồi:

**THỨ TỰ: Secret mới lên cụm TRƯỚC, `ALTER USER` NGAY SAU.** Postgres không giữ được hai
password cùng lúc. Secret đổi → Reloader restart frankenphp/worker → pod mới đứng
NotReady (chưa đăng nhập được DB) → chạy `ALTER USER` → pod mới Ready, readiness probe tự
chuyển traffic sang. Làm `ALTER` trước thì mọi pod cũ hỏng DB khoảng 30 giây trong lúc
chờ rollout.

```bash
K="kubectl --context multipass"
# Password đi qua pipe, không nằm trên dòng lệnh. Chỉ chữ và số (seal-secrets.sh ép).
$K -n frankenphp get secret frankenphp-secret -o jsonpath='{.data.password}' | base64 -d \
  | python3 -c 'import sys; print(f"ALTER USER postgres WITH PASSWORD \x27{sys.stdin.read()}\x27;")' \
  | $K -n frankenphp exec -i postgres-0 -- psql -U postgres -v ON_ERROR_STOP=1 -q
```

RabbitMQ tự xử lý: Secret `rabbitmq-definitions` đổi thì Reloader restart StatefulSet,
broker nạp lại `definitions.json` lúc boot. Mất khoảng 20–40 giây không publish được.

Job migration (hook PostSync) có thể fail rồi thử lại cho tới khi `ALTER USER` xong. Bình
thường.

## Chuyển giao lần đầu: Secret thường → SealedSecret

Chỉ làm một lần, khi Secret `frankenphp-secret` đang do ArgoCD quản dạng chữ thường.

**Đã diễn tập (31/10/2026):** controller TỪ CHỐI ghi đè Secret không do nó tạo ("already
exists and is not managed by SealedSecret"). Gắn `sealedsecrets.bitnami.com/managed=true`
SAU khi SealedSecret đã apply thì controller KHÔNG quay lại xử lý, kể cả khi gắn thêm
annotation lên SealedSecret để "đánh thức". Phải gắn TRƯỚC.

1. Tắt auto-sync:
   `$K -n argocd patch application frankenphp --type merge -p '{"spec":{"syncPolicy":{"automated":null}}}'`
2. Cho controller nhận Secret, và gỡ nó khỏi tay ArgoCD (Secret không còn trong git, để
   ArgoCD theo dõi thì `prune` sẽ xóa nó):
   ```bash
   $K -n frankenphp annotate secret frankenphp-secret sealedsecrets.bitnami.com/managed=true
   $K -n frankenphp annotate secret frankenphp-secret argocd.argoproj.io/tracking-id-
   $K -n argocd annotate application frankenphp argocd.argoproj.io/refresh=hard --overwrite
   # Phải RỖNG: ArgoCD không còn coi Secret là của nó
   $K -n argocd get application frankenphp -o jsonpath='{range .status.resources[?(@.kind=="Secret")]}{.name}{"\n"}{end}'
   ```
3. Trỏ ArgoCD vào branch và kích sync có prune (ConfigMap `rabbitmq-definitions` cũ bị
   xóa, `rabbitmq-config` và 2 SealedSecret được tạo):
   ```bash
   $K -n argocd patch application frankenphp --type merge -p '{"spec":{"source":{"targetRevision":"<branch>"}}}'
   REV=$(git rev-parse origin/<branch>)
   $K -n argocd patch application frankenphp --type merge -p "{\"operation\":{\"initiatedBy\":{\"username\":\"sealed-secrets\"},
     \"sync\":{\"revision\":\"$REV\",\"prune\":true,\"syncOptions\":[\"CreateNamespace=true\",\"ServerSideApply=true\"]}}}"
   ```
4. Ngay khi `frankenphp-secret` có `ownerReferences` trỏ SealedSecret: chạy `ALTER USER`
   như mục trên.
5. Kiểm tra Ingress, worker, RabbitMQ. Merge PR, rồi
   `$K apply -n argocd -f devops/argocd/argocd-application.yaml` để về main.

## Cụm khác

- **VPS:** cài controller như trên trên VPS, sao lưu khóa của VPS, `--fetch-cert` vào
  `devops/overlays/vps/sealed-secrets-cert.pem`, `seal-secrets.sh seal vps`, thêm
  `sealed-secrets.yaml` vào resources của overlay vps. Password của VPS KHÁC multipass.
- **minikube** (học ở local, không ArgoCD): không cần controller,
  `POSTGRES_PASSWORD=... RABBITMQ_PASSWORD=... devops/scripts/seal-secrets.sh plain | kubectl apply -f -`.
  KHÔNG chuyển hướng đầu ra vào file trong repo.

## Đổi khóa niêm phong

Tự đổi đã tắt (`keyrenewperiod=0`). Khi cần (lộ khóa, định kỳ), làm theo mục "Key
rotation" trong README của bitnami/sealed-secrets cho đúng phiên bản controller, rồi: sao
lưu lại TOÀN BỘ khóa (khóa cũ vẫn được giữ để mở bản cũ), fetch cert mới, niêm phong lại
mọi Secret. Đổi khóa KHÔNG làm bản cũ trong lịch sử git an toàn hơn: nếu khóa cũ lộ thì
phải đổi cả password.
