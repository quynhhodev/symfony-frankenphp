# Chuyển app từ namespace `default` sang `frankenphp`

> Soạn 31/10/2026 cho cụm multipass (k8s v1.35.9, ArgoCD v3.5.1). Các bước kiểm chứng ở
> mục "Đã kiểm trước" chạy trên chính cụm này; phần di chuyển (mục 2 trở đi) CHƯA chạy.

## Vì sao phải làm tay, không để ArgoCD tự sync

PVC gắn với namespace. ArgoCD sync thẳng manifest mới thì nó dựng Postgres **rỗng** ở
`frankenphp`, chạy migration lên DB rỗng, rồi (vì `prune: true`) xoá Postgres ở `default`.
App lên xanh nhưng **mất sạch dữ liệu**. PVC cũ vẫn còn (ArgoCD không quản PVC), nhưng
không ai đọc nó nữa.

Thứ tự đúng: dừng ghi → dump → dựng Postgres mới → restore → dựng phần còn lại → kiểm →
mới cho ArgoCD dọn `default`.

## Đã kiểm trước (31/10/2026)

- Dump `myapp_db` (Postgres 15.19, 46MB data, `pg_dump -Fc` trong pod) restore vào
  `postgres:15-alpine` ở máy local: số dòng khớp, đủ 4 migration. Backup ở
  `~/backups/symfony-frankenphp/2026-10-31-pre-namespace/` (có `SHA256SUMS`).
- RabbitMQ: 2 queue (`messages`, `app_cdc`) đều 0 message. Definitions nạp từ ConfigMap,
  nên RabbitMQ không cần di chuyển dữ liệu.
- Mọi kết nối dùng tên ngắn (`postgres-service`, `rabbitmq`), chạy được ở namespace mới.
- Namespace thử có `enforce=baseline`: cả 6 workload qua admission. Postgres, RabbitMQ,
  Reloader chỉ bị CẢNH BÁO restricted. LimitRange điền mặc định cho pod quên khai
  resources, Quota chặn Service NodePort.
- `kubectl kustomize`: mọi resource sang `frankenphp`, RoleBinding/ClusterRoleBinding của
  Reloader đổi subject theo. Chuỗi duy nhất phải sửa tay là annotation middleware.

## Downtime

Từ bước 2.1 tới hết bước 4: khoảng 5–10 phút. Người chạy phải có mặt từ đầu tới cuối.

---

## 1. Chuẩn bị (chưa downtime)

```bash
K="kubectl --context multipass"
BR=feat/namespace-frankenphp                       # branch của PR này
B=~/backups/symfony-frankenphp/$(date +%F)-cutover
mkdir -p "$B" && chmod 700 "$B"

# ArgoCD phải đang xanh trên main trước khi bắt đầu
$K -n argocd get application frankenphp -o jsonpath='{.status.sync.status}/{.status.health.status}{"\n"}'

# Tắt auto-sync. Từ đây tới bước 4, ArgoCD không tự áp hay xoá gì.
$K -n argocd patch application frankenphp --type merge -p '{"spec":{"syncPolicy":{"automated":null}}}'
```

**Quay lại:** bật lại auto-sync trên main:
`$K -n argocd patch application frankenphp --type merge -p '{"spec":{"syncPolicy":{"automated":{"prune":true}}}}'`

## 2. Dừng ghi và dump lần cuối (BẮT ĐẦU downtime)

**2.1** Về 0 pod. HPA ngừng tác động khi Deployment có `replicas: 0` (condition
`ScalingActive=False`, reason `ScalingDisabled`), nên không tự kéo lên lại.

```bash
$K -n default scale deploy frankenphp messenger-worker --replicas=0
$K -n default wait --for=delete pod -l 'app in (frankenphp,messenger-worker)' --timeout=120s
$K -n default get hpa frankenphp -o jsonpath='{.status.conditions[?(@.type=="ScalingActive")].reason}{"\n"}'
```

**2.2** Dump trong pod (client trùng version server), đếm dòng, checksum.

```bash
$K -n default exec postgres-0 -- pg_dump -U postgres -d myapp_db -Fc > "$B/myapp_db.dump"
COUNT_SQL="select 'user='||(select count(*) from \"user\")||' customer='||(select count(*) from customer)||' messenger_messages='||(select count(*) from messenger_messages)||' doctrine_migration_versions='||(select count(*) from doctrine_migration_versions);"
$K -n default exec postgres-0 -- psql -U postgres -d myapp_db -Atc "$COUNT_SQL" | tee "$B/row-counts.txt"
(cd "$B" && sha256sum myapp_db.dump > SHA256SUMS)
```

**Quay lại:** `$K -n default scale deploy frankenphp --replicas=2 && $K -n default scale deploy messenger-worker --replicas=1`,
rồi bật lại auto-sync như bước 1. Dữ liệu ở `default` chưa bị đụng tới.

## 3. Dựng Postgres ở `frankenphp` và restore

**3.1** Tạo namespace với đúng label mà ArgoCD sẽ quản (`managedNamespaceMetadata`).

```bash
$K create namespace frankenphp
$K label namespace frankenphp pod-security.kubernetes.io/enforce=baseline \
  pod-security.kubernetes.io/warn=restricted pod-security.kubernetes.io/audit=restricted
```

**3.2** Chỉ áp những gì Postgres cần. Dùng `--field-manager=argocd-controller` để ArgoCD
nhận làm của mình ở bước 4, không giành trường với kubectl.

```bash
git fetch origin "$BR"
git worktree add /tmp/ns-cutover "origin/$BR"
kubectl kustomize /tmp/ns-cutover/devops/overlays/multipass | python3 -c '
import sys, yaml
want = {("ResourceQuota","frankenphp"), ("LimitRange","frankenphp"), ("ServiceAccount","frankenphp"),
        ("Secret","frankenphp-secret"), ("ConfigMap","frankenphp"),
        ("Service","postgres-service"), ("Service","postgres-headless"), ("StatefulSet","postgres")}
docs = [d for d in yaml.safe_load_all(sys.stdin) if d and (d["kind"], d["metadata"]["name"]) in want]
assert len(docs) == len(want), f"thiếu: {want - {(d['kind'], d['metadata']['name']) for d in docs}}"
print(yaml.safe_dump_all(docs))' > "$B/phase3.yaml"
$K apply --server-side --field-manager=argocd-controller -f "$B/phase3.yaml"
$K -n frankenphp rollout status sts/postgres --timeout=180s
```

**3.3** Restore vào `myapp_db` rỗng (Postgres tự tạo từ `POSTGRES_DB`), rồi so số dòng.

```bash
$K -n frankenphp exec -i postgres-0 -- pg_restore -U postgres -d myapp_db --no-owner --exit-on-error < "$B/myapp_db.dump"
$K -n frankenphp exec postgres-0 -- psql -U postgres -d myapp_db -Atc "$COUNT_SQL" > "$B/row-counts-new.txt"
diff "$B/row-counts.txt" "$B/row-counts-new.txt" && echo "KHỚP"
```

Không khớp thì DỪNG, không sang bước 4.

**Quay lại:** `$K delete namespace frankenphp` (xoá luôn PVC mới, dữ liệu gốc vẫn ở
`default`), rồi làm như "Quay lại" của bước 2.

## 4. Dựng phần còn lại

**4.1** Xoá 2 Ingress cũ. Traefik bật `allowEmptyServices=true`: Ingress cũ trỏ vào Service
không còn pod vẫn nhận một phần request `frankenphp.local` và trả 503, cho tới khi bị xoá.

```bash
$K -n default delete ingress frankenphp-ingress rabbitmq-ingress
```

**4.2** Trỏ ArgoCD vào branch, auto-sync **KHÔNG prune**: dựng mọi thứ ở `frankenphp`,
chạy hook migration (không có migration mới vì bản dump đã có `doctrine_migration_versions`),
nhưng chưa xoá gì ở `default`.

```bash
$K -n argocd patch application frankenphp --type merge -p "{\"spec\":{
  \"source\":{\"targetRevision\":\"$BR\"},
  \"destination\":{\"namespace\":\"frankenphp\"},
  \"syncPolicy\":{\"automated\":{\"prune\":false},
    \"managedNamespaceMetadata\":{\"labels\":{\"pod-security.kubernetes.io/enforce\":\"baseline\",
      \"pod-security.kubernetes.io/warn\":\"restricted\",\"pod-security.kubernetes.io/audit\":\"restricted\"}}}}}"
$K -n frankenphp rollout status deploy/frankenphp --timeout=300s
$K -n frankenphp rollout status deploy/messenger-worker --timeout=300s
$K -n frankenphp wait --for=condition=complete job/doctrine-migrations --timeout=300s
```

**Quay lại** (trước bước 6, `default` vẫn còn nguyên): trỏ ArgoCD về `main` với
`destination.namespace: default` và auto-sync KHÔNG prune. Nó dựng lại 2 Ingress cũ.
Rồi scale như bước 2, và `$K delete namespace frankenphp`.

## 5. Kiểm tra (HẾT downtime khi mọi dòng dưới đây đúng)

```bash
curl -s -H 'Host: frankenphp.local' http://10.76.44.99:32723/health/ready   # {"status":"ok"}
curl -s -H 'Host: frankenphp.local' http://10.76.44.99:32723/users          # đủ số phần tử như row-counts.txt (mỗi user hiện là {}, bug đã biết)
$K -n frankenphp get pods                                      # mọi pod Running, Job Completed
$K -n frankenphp logs deploy/messenger-worker --tail=5         # worker đang nhận queue
$K -n frankenphp get hpa frankenphp                            # TARGETS có số, không <unknown>
$K -n frankenphp describe quota frankenphp | sed -n '/Resource/,$p'
$K -n frankenphp exec deploy/frankenphp -- ls /var/run/secrets/kubernetes.io 2>&1   # phải báo không có thư mục: không gắn token
# OutOfSync là ĐÚNG ở bước này: resource cũ ở `default` đang chờ prune. Chỉ cần
# không có resource nào ở `frankenphp` lệch:
$K -n argocd get application frankenphp -o jsonpath='{range .status.resources[?(@.namespace=="frankenphp")]}{.kind}/{.name} {.status}{"\n"}{end}' | grep -v Synced || echo "frankenphp: mọi resource Synced"
```

## 6. Cho ArgoCD dọn `default`

Chỉ làm khi mục 5 đúng hết. Bật prune: ArgoCD xoá các resource cũ ở `default`
(Deployment, StatefulSet, Service, ConfigMap, Secret, Middleware, HPA, PDB, Reloader).
**PVC cũ KHÔNG bị xoá**: ArgoCD không quản PVC, StatefulSet giữ PVC khi bị xoá.

```bash
$K -n argocd patch application frankenphp --type merge -p '{"spec":{"syncPolicy":{"automated":{"prune":true}}}}'
$K -n default get all,cm,secret,ingress,pvc              # chỉ còn svc/kubernetes, kube-root-ca.crt, reloader-meta-info, 2 PVC cũ
$K -n argocd get application frankenphp -o jsonpath='{.status.sync.status}/{.status.health.status}{"\n"}'   # Synced/Healthy
```

**Quay lại sau bước này:** trỏ ArgoCD về `main` cũ (`destination.namespace: default`).
StatefulSet postgres dựng lại ở `default` và gắn lại đúng PVC `postgres-storage-postgres-0`,
tức dữ liệu tại thời điểm bước 2.2. Ghi mới sau đó phải dump từ `frankenphp` sang.

## 7. Merge PR, đưa ArgoCD về main

```bash
# Sau khi PR đã merge:
$K apply -n argocd -f devops/argocd/argocd-application.yaml     # main + destination frankenphp
$K -n argocd get application frankenphp -o jsonpath='{.spec.source.targetRevision} {.status.sync.status}/{.status.health.status}{"\n"}'
git worktree remove /tmp/ns-cutover
```

## 8. Dọn dẹp, SAU VÀI NGÀY và có người đồng ý

```bash
$K -n default delete pvc postgres-storage-postgres-0 rabbitmq-storage-rabbitmq-0   # mất hẳn lưới quay lại
$K -n default delete configmap reloader-meta-info                                  # Reloader cũ để lại
```
