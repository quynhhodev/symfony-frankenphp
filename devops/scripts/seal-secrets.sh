#!/usr/bin/env bash
# NƠI DUY NHẤT sinh Secret của app. Không ai viết Secret bằng tay nữa.
#
#   # Niêm phong cho một cụm (cần kubeseal và cert của cụm đó trong overlay):
#   POSTGRES_PASSWORD=$(openssl rand -hex 24) RABBITMQ_PASSWORD=$(openssl rand -hex 24) \
#     devops/scripts/seal-secrets.sh seal multipass
#
#   # CI: dựng Secret bằng giá trị giả, kiểm tra các chỗ dùng chung password khớp nhau:
#   devops/scripts/seal-secrets.sh check
#
#   # Cụm học ở local (minikube), KHÔNG commit đầu ra:
#   POSTGRES_PASSWORD=... RABBITMQ_PASSWORD=... devops/scripts/seal-secrets.sh plain | kubectl apply -f -
#
# Mỗi password nhập MỘT lần rồi dựng ra mọi chỗ cần nó: RABBITMQ_PASSWORD vào cả DSN của
# app lẫn definitions.json của broker, POSTGRES_PASSWORD vào cả `password` lẫn
# `database_url`. Hai chỗ không thể lệch nhau, thay cho guard so password cũ trong
# test.yml (CI không đọc được bản đã niêm phong).
#
# Password chỉ đi qua biến môi trường và pipe: không ghi ra đĩa, không in ra màn hình,
# không nằm trên dòng lệnh (nên không lộ qua `ps`).
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
NS=$(awk '/^namespace:/{print $2}' "$ROOT/devops/base/kustomization.yaml")
SECRETS=(frankenphp-secret rabbitmq-definitions)

# In ra JSON của MỘT Secret (tên ở $1), đọc password từ biến môi trường.
render() {
  python3 - "$NS" "$1" <<'EOF'
import json, os, re, sys

ns, name = sys.argv[1], sys.argv[2]
pg, rmq = os.environ.get("POSTGRES_PASSWORD", ""), os.environ.get("RABBITMQ_PASSWORD", "")
for var, value in (("POSTGRES_PASSWORD", pg), ("RABBITMQ_PASSWORD", rmq)):
    # Password nằm TRONG URL (database_url, DSN): ký tự như / + @ : làm hỏng URL.
    if not re.fullmatch(r"[A-Za-z0-9]{24,}", value):
        sys.exit(f"{var} phải có ít nhất 24 ký tự, chỉ chữ và số (gợi ý: openssl rand -hex 24)")

if name == "frankenphp-secret":
    data = {
        "password": pg,
        "database_url": f"postgresql://postgres:{pg}@postgres-service:5432/myapp_db?serverVersion=15&charset=utf8",
        # `%2f` là vhost `/` đã url-encode.
        "messenger_transport_dsn": f"amqp://quynh:{rmq}@rabbitmq:5672/%2f/messages",
    }
    # Token của bên thứ ba: tùy chọn, workload khai `optional: true`.
    if os.environ.get("FACEBOOK_ACCESS_TOKEN"):
        data["facebook_access_token"] = os.environ["FACEBOOK_ACCESS_TOKEN"]
elif name == "rabbitmq-definitions":
    # Broker nạp lại file này MỖI LẦN BOOT (load_definitions), nên đây là nguồn sự thật
    # cho credential RabbitMQ. Import nhận `password` plaintext và tự hash.
    definitions = {
        "users": [{"name": "quynh", "password": rmq, "tags": ["administrator"]}],
        "vhosts": [{"name": "/"}],
        "permissions": [{"user": "quynh", "vhost": "/", "configure": ".*", "write": ".*", "read": ".*"}],
    }
    data = {"definitions.json": json.dumps(definitions, indent=2)}
else:
    sys.exit(f"không biết Secret {name}")

print(json.dumps({"apiVersion": "v1", "kind": "Secret", "type": "Opaque",
                  "metadata": {"name": name, "namespace": ns}, "stringData": data}))
EOF
}

case "${1:-}" in
  seal)
    overlay=${2:?cần tên overlay, ví dụ: seal multipass}
    dir="$ROOT/devops/overlays/$overlay"
    cert="$dir/sealed-secrets-cert.pem"
    [ -f "$cert" ] || { echo "LỖI: thiếu $cert (kubeseal --fetch-cert của cụm đó)" >&2; exit 1; }
    command -v kubeseal >/dev/null || { echo "LỖI: chưa cài kubeseal" >&2; exit 1; }
    out="$dir/sealed-secrets.yaml"
    tmp=$(mktemp "$dir/.sealed-secrets.XXXXXX")
    trap 'rm -f "$tmp"' EXIT
    {
      echo "# SINH BỞI devops/scripts/seal-secrets.sh, đừng sửa tay. Chỉ controller của"
      echo "# cụm $overlay mở được (khóa riêng của cụm, scope strict: đúng namespace và tên)."
      for s in "${SECRETS[@]}"; do
        echo "---"
        render "$s" | kubeseal --cert "$cert" --scope strict --format yaml
      done
    } > "$tmp"
    mv "$tmp" "$out"
    trap - EXIT
    echo "đã ghi $out (${#SECRETS[@]} SealedSecret, namespace $NS)"
    ;;

  plain)
    for s in "${SECRETS[@]}"; do render "$s"; done
    ;;

  check)
    export POSTGRES_PASSWORD=pgCheckOnly000000000000000 RABBITMQ_PASSWORD=rmqCheckOnly00000000000000
    for s in "${SECRETS[@]}"; do render "$s"; done | python3 -c '
import json, sys
from urllib.parse import urlsplit
docs = {d["metadata"]["name"]: d for d in map(json.loads, sys.stdin)}
app = docs["frankenphp-secret"]["stringData"]
definitions = json.loads(docs["rabbitmq-definitions"]["stringData"]["definitions.json"])
db, dsn = urlsplit(app["database_url"]), urlsplit(app["messenger_transport_dsn"])
users = {u["name"]: u["password"] for u in definitions["users"]}
problems = []
if db.password != app["password"]:
    problems.append("database_url khác password")
if users.get(dsn.username) != dsn.password:
    problems.append(f"DSN dùng user {dsn.username} nhưng password khác definitions.json")
if problems:
    sys.exit("LỖI: " + "; ".join(problems))
print("OK: password trong database_url, DSN và definitions.json khớp nhau")'
    ;;

  *)
    sed -n '2,21p' "$0" | sed 's/^# \{0,1\}//'
    exit 1
    ;;
esac
