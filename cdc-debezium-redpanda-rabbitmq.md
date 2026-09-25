# Blueprint: Postgres CDC → Debezium → Redpanda Connect → RabbitMQ → Symfony Messenger

Tách từ `gci-business-backend` (develop `783391e9f`, pipeline `redpanda-connect/gci-business.yaml`) để dựng lại trong một project test.

Đã kiểm chứng ngày 24/09/2026:
- `redpanda-connect lint` và `redpanda-connect test` pass với image `docker.redpanda.com/redpandadata/connect:4.67.5`.
- Các unit test Bloblang ở mục 6.5 đã được thử cố tình làm sai một assertion, và bị fail đúng như mong đợi.
- Phần Symfony lấy từ config đang chạy trong repo gốc, nhưng chưa được dựng lại trong một project mới.

Ví dụ xuyên suốt: bảng `customer` trong DB `app`. Transport Messenger tên `cdc`.

---

## 1. Luồng tổng quan

```
Postgres (WAL, wal_level=logical)
   │  Debezium đọc replication slot
   ▼
Kafka topic  app.public.customer          ← JSON Debezium {before, after, source, op}
   │  Redpanda Connect đọc với consumer group riêng
   ▼
Redpanda Connect (app.yaml)
   1. guard nguồn     source.name == "app" && schema == "public"
   2. route theo bảng source.table → resource/app/customer.yaml
   3. mapping         → mảng envelope [{type, body}]
   4. unarchive       mỗi envelope thành 1 message
   5. meta type = FQCN, payload = body
   6. publish_messenger → AMQP
   ▼
RabbitMQ  exchange fanout app_cdc → queue app_cdc   ← header "type" + body JSON
   ▼
Worker PHP: messenger:consume cdc
   Symfony Serializer: deserialize(body, header type) → object
   → handler (#[AsMessageHandler]) chạy theo class của message
```

Dữ liệu đổi định dạng ba lần: JSON của Debezium → envelope `{type, body}` → message AMQP → object PHP.

## 2. Vai trò từng thành phần

| Thành phần | Làm gì | Không làm gì |
|---|---|---|
| Debezium | Đọc WAL, ghi event vào **topic** Kafka | Không biết consumer group hay RabbitMQ |
| Kafka | Lưu event theo topic; consumer group giữ offset đã đọc | Không lọc hay biến đổi dữ liệu |
| Redpanda Connect | Lọc nguồn, route theo bảng, map event thành envelope Messenger, publish AMQP | Không chạy logic nghiệp vụ |
| RabbitMQ | Đẩy message từ exchange vào queue đã bind | Không chọn worker hay handler |
| Worker Symfony | Consume queue của transport, decode theo header `type`, chạy handler | Không đọc Kafka |

"Đúng worker" được quyết định ở hai chỗ:
- pipeline cố định transport, nên cố định cả exchange và queue;
- lệnh `messenger:consume <transport>` quyết định process nào đọc queue đó.

Handler do Symfony chọn theo class của message.

## 3. Cấu trúc thư mục

```
compose.yaml
docker/postgres/init.sql
docker/debezium/app-connector.json
redpanda-connect/
  app.yaml                      # pipeline
  app_benthos_test.yaml         # unit test Bloblang (tự tìm theo tên <pipeline>_benthos_test.yaml)
  template/
    consume_kafka.yaml
    publish_messenger.yaml
  resource/app/
    customer.yaml               # 1 file cho mỗi bảng: processor + output resource
config/packages/messenger.yaml
src/Message/CustomerChangedMessage.php
src/Message/WelcomeCustomerMessage.php
src/MessageHandler/CustomerChangedMessageHandler.php
```

## 4. Hạ tầng (docker compose)

Rút gọn từ `compose.yaml` của repo gốc. Service `worker` là image PHP của bạn, cần có `ext-amqp`.

```yaml
services:
    postgres:
        image: postgres:15-alpine
        command: ["postgres", "-c", "wal_level=logical"]
        environment:
            POSTGRES_DB: app
            POSTGRES_USER: app
            POSTGRES_PASSWORD: app
        ports: ["5432:5432"]
        volumes:
            - ./docker/postgres/init.sql:/docker-entrypoint-initdb.d/01-init.sql:ro

    kafka:
        image: apache/kafka:3.7.0
        environment:
            CLUSTER_ID: app-cdc-cluster
            KAFKA_NODE_ID: 1
            KAFKA_PROCESS_ROLES: broker,controller
            KAFKA_CONTROLLER_QUORUM_VOTERS: 1@kafka:9091
            KAFKA_CONTROLLER_LISTENER_NAMES: CONTROLLER
            KAFKA_INTER_BROKER_LISTENER_NAME: INTERNAL
            KAFKA_LISTENERS: CONTROLLER://:9091,INTERNAL://:9093,CLIENT://:9092,DEBEZIUM://:9094
            KAFKA_ADVERTISED_LISTENERS: INTERNAL://kafka:9093,CLIENT://kafka:9092,DEBEZIUM://kafka:9094
            KAFKA_LISTENER_SECURITY_PROTOCOL_MAP: CONTROLLER:PLAINTEXT,INTERNAL:PLAINTEXT,CLIENT:PLAINTEXT,DEBEZIUM:PLAINTEXT
            KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
            KAFKA_TRANSACTION_STATE_LOG_REPLICATION_FACTOR: 1
            KAFKA_TRANSACTION_STATE_LOG_MIN_ISR: 1
            KAFKA_GROUP_INITIAL_REBALANCE_DELAY_MS: 0

    connect:
        image: quay.io/debezium/connect:2.0
        environment:
            BOOTSTRAP_SERVERS: kafka:9094
            GROUP_ID: 1
            CONFIG_STORAGE_TOPIC: connect_configs
            OFFSET_STORAGE_TOPIC: connect_offsets
            STATUS_STORAGE_TOPIC: connect_statuses
        depends_on: [kafka, postgres]

    register-connector:
        image: curlimages/curl
        depends_on: [connect]
        command: >
            -i -f -XPOST -H "Content-Type: application/json"
            -d @/src/app-connector.json
            --retry 6 --retry-all-errors --retry-connrefused --retry-delay 10
            http://connect:8083/connectors/
        volumes:
            - ./docker/debezium/app-connector.json:/src/app-connector.json:ro

    rabbitmq:
        image: rabbitmq:3.13-management
        ports: ["5672:5672", "15672:15672"]

    worker:
        build: .                      # image PHP của bạn (cần ext-amqp)
        command: ["php", "bin/console", "messenger:consume", "cdc", "-vv", "--time-limit=3600"]
        environment:
            MESSENGER_TRANSPORT_DSN_PREFIX: amqp://guest:guest@rabbitmq:5672/%2f/app_
        depends_on: [rabbitmq, postgres]

    redpanda-connect:
        image: docker.redpanda.com/redpandadata/connect:4.67.5   # entrypoint: /redpanda-connect
        working_dir: /app
        command:
            - -t
            - ./redpanda-connect/template/*.yaml
            - -r
            - ./redpanda-connect/resource/app/*.yaml
            - -c
            - ./redpanda-connect/app.yaml
        environment:
            KAFKA_BOOTSTRAP: kafka:9092
            KAFKA_APP_TOPICS: app.public.customer
            KAFKA_CONSUMER_GROUP: app.cdc
            KAFKA_SASL_MECHANISM: none
            MESSENGER_TRANSPORT_DSN_PREFIX: amqp://guest:guest@rabbitmq:5672/%2f/app_
        volumes:
            - ./redpanda-connect:/app/redpanda-connect:ro
        depends_on: [kafka, rabbitmq]
```

`docker/postgres/init.sql` tạo bảng trước khi connector được đăng ký:

```sql
create table if not exists customer (id uuid primary key, email text);
```

Bảng phải có trước khi đăng ký connector. Với `publication.autocreate.mode=filtered`, nếu chưa có bảng nào khớp `table.include.list` thì task Debezium fail với lỗi `Unable to create filtered publication` (đã gặp ở repo gốc).

Glob trong `command` được truyền nguyên văn vì không có shell, và redpanda-connect tự expand khi `run`/`test`. Riêng `lint -r` không expand glob, nên khi lint phải liệt kê từng file.

## 5. Debezium connector

`docker/debezium/app-connector.json`:

```json
{
  "name": "app",
  "config": {
    "connector.class": "io.debezium.connector.postgresql.PostgresConnector",
    "topic.prefix": "app",
    "database.hostname": "postgres",
    "database.user": "app",
    "database.password": "app",
    "database.dbname": "app",
    "schema.include.list": "public",
    "table.include.list": "public.customer",
    "plugin.name": "pgoutput",
    "publication.autocreate.mode": "filtered",
    "snapshot.mode": "initial",
    "tombstones.on.delete": "false",
    "decimal.handling.mode": "double",
    "key.converter": "org.apache.kafka.connect.json.JsonConverter",
    "value.converter": "org.apache.kafka.connect.json.JsonConverter",
    "key.converter.schemas.enable": "false",
    "value.converter.schemas.enable": "false"
  }
}
```

- `topic.prefix` trở thành `source.name` trong event và là tiền tố topic (`app.public.customer`). Guard ở mục 6.3 so khớp đúng giá trị này.
- `value.converter.schemas.enable=false` là bắt buộc. Để `true` thì event bị bọc trong `{schema, payload}`, `this.source.table` thành null, và pipeline drop mọi thứ mà không báo lỗi.
- Muốn có `before` trong event update thì chạy `ALTER TABLE customer REPLICA IDENTITY FULL;`. Mặc định (DEFAULT) thì `before` của update là null.

## 6. Redpanda Connect

### 6.1 `template/consume_kafka.yaml`

```yaml
name: consume_kafka
type: input

fields:
    -   name: topics
        type: string
        kind: list
        advanced: true
    -   name: start_offset
        type: string
        default: earliest
        advanced: true

mapping: |
    root.redpanda = {
        "seed_brokers": [
            env("KAFKA_BOOTSTRAP").or("kafka:9092"),
        ],
        "tls": {
            "enabled": env("KAFKA_ENABLED_TLS").or("false").bool(),
            "skip_cert_verify": true,
        },
        "consumer_group": env("KAFKA_CONSUMER_GROUP").or("app.cdc"),
        "sasl": [
            {
                "mechanism": env("KAFKA_SASL_MECHANISM").or("none"),
                "username": env("KAFKA_USERNAME").or("kafka"),
                "password": env("KAFKA_PASSWORD").or("kafka")
            }
        ],
        "topics": this.topics,
        "start_offset": this.start_offset
    }
```

Dùng field `start_offset`, không dùng `start_from_oldest`. Field đó đã deprecated và xung đột với `start_offset`; `lint` không bắt được lỗi này, chỉ lúc input khởi động mới báo.

### 6.2 `template/publish_messenger.yaml`

```yaml
name: publish_messenger
type: output

fields:
    -   name: transport
        type: string
    -   name: label
        type: string
        default: ""

mapping: |
    let dsn_prefix = env("MESSENGER_TRANSPORT_DSN_PREFIX").or("amqp://guest:guest@rabbitmq:5672/%2f/app_")
    let amqp_url = env("REDPANDA_AMQP_URL").or($dsn_prefix.re_replace_all("/[^/]*$", ""))
    let exchange = $dsn_prefix.split("/").index(-1) + this.transport

    root.label = this.label
    root.fallback = [
        {
            "label": this.label + "_retry",
            "retry": {
                "max_retries": 10,
                "backoff": {
                    "initial_interval": "500ms",
                    "max_interval": "5s",
                },
                "output": {
                    "label": this.label,
                    "amqp_0_9": {
                        "urls": [$amqp_url],
                        "exchange": $exchange,
                        "exchange_declare": {
                            "enabled": false,
                        },
                        "key": "",
                        "content_type": "application/json",
                        "persistent": true,
                        "max_in_flight": 1,
                    }
                }
            }
        },
        {
            "label": this.label + "_reject",
            "reject": "[" + this.label + "] AMQP publish failed | Data: ${! json() }"
        }
    ]
```

- **Tên exchange** được suy ra từ `MESSENGER_TRANSPORT_DSN_PREFIX`, giống cách Symfony làm: prefix `…/%2f/app_` cộng transport `cdc` cho ra `app_cdc`. Hai bên luôn khớp mà không phải hardcode.
- **`exchange_declare: false`**: Symfony tự tạo exchange, queue và binding. Nếu redpanda tạo exchange trước, message sẽ vào một fanout chưa có queue nào và bị vứt đi âm thầm. Tắt declare thì exchange thiếu sẽ báo lỗi rõ ràng, và retry giữ offset cho tới khi worker dựng xong.
- **`max_in_flight: 1`**: giữ thứ tự publish.
- **Metadata thành header AMQP**: vì vậy pipeline phải xoá metadata `kafka_*` trước khi publish.

### 6.3 Pipeline `app.yaml`

```yaml
input:
    consume_kafka:
        topics:
            - '${KAFKA_APP_TOPICS:app.public.customer}'
        start_offset: latest

pipeline:
    threads: 1
    processors:
        -   switch:
                -   check: >-
                        this.source.name.or("") != "app" ||
                        this.source.schema.or("") != "public"
                    processors:
                        -   mapping: 'root = deleted()'

        -   switch:
                -   check: 'this.source.table == "customer"'
                    processors:
                        -   resource: 'processor_customer'
                -   processors:
                        -   mapping: 'root = deleted()'

        -   unarchive:
                format: json_array

        -   mapping: |
                meta = deleted()
                meta type = this.type
                root = this.body

        -   switch:
                -   check: errored()
                    processors:
                        -   log:
                                level: ERROR
                                message: 'CDC event parked: ${! error() } | ${! content() }'

output:
    switch:
        cases:
            -   check: errored()
                output:
                    drop: {}
            -   output:
                    retry:
                        max_retries: 0
                        output:
                            resource: 'output_app_messenger'

logger:
    level: '${LOG_LEVEL:INFO}'
    format: json
```

- **Guard phải đứng đầu.** `.or("")` làm record rỗng hoặc không phải JSON (ví dụ tombstone) rơi vào nhánh drop. Nếu đưa switch theo `source.table` lên trước, nó sẽ lỗi "unable to reference message as structured".
- **`start_offset: latest`**: group mới không đọc lại cả retention, vì message có side effect.
- **`threads: 1`**: giữ thứ tự. Mọi bảng trên cùng topic chia chung một thứ tự.
- **Output khác repo gốc** (xem mục 11). Event lỗi mapping được ghi log ERROR rồi drop. Chỉ phần publish AMQP được retry vô hạn, để RabbitMQ down không làm mất message.

### 6.4 Resource `resource/app/customer.yaml`

```yaml
processor_resources:
    -   label: processor_customer
        switch:
            -   check: 'this.op == "c"'
                processors:
                    -   mapping: |
                            root = [
                                {
                                    "type": "App\\Message\\CustomerChangedMessage",
                                    "body": {
                                        "customerId": this.after.id.string(),
                                        "email": this.after.email.or(null)
                                    }
                                },
                                {
                                    "type": "App\\Message\\WelcomeCustomerMessage",
                                    "body": {
                                        "customerId": this.after.id.string()
                                    }
                                }
                            ]
            -   check: 'this.op == "u"'
                processors:
                    -   mapping: |
                            root = [
                                {
                                    "type": "App\\Message\\CustomerChangedMessage",
                                    "body": {
                                        "customerId": this.after.id.string(),
                                        "email": this.after.email.or(null)
                                    }
                                }
                            ]
            -   processors:
                    -   mapping: 'root = deleted()'

output_resources:
    -   label: output_app_messenger
        publish_messenger:
            label: app_publish
            transport: cdc
```

- **Một event có thể sinh nhiều envelope.** Op `c` ở đây sinh 2; op `d` và snapshot `r` bị drop.
- **Hợp đồng với PHP:** `type` là FQCN của class message, key trong `body` phải trùng **tên tham số constructor**.

### 6.5 Unit test Bloblang `app_benthos_test.yaml`

```yaml
tests:
    -   name: insert emits two envelopes
        target_processors: '/pipeline/processors'
        input_batch:
            -   content: '{"before":null,"after":{"id":"0199a1b2-0000-7000-8000-000000000001","email":"a@example.com"},"source":{"name":"app","schema":"public","table":"customer"},"op":"c"}'
        output_batches:
            -   -   metadata_equals:
                        type: 'App\Message\CustomerChangedMessage'
                    json_equals: {"customerId": "0199a1b2-0000-7000-8000-000000000001", "email": "a@example.com"}
                -   metadata_equals:
                        type: 'App\Message\WelcomeCustomerMessage'
                    json_equals: {"customerId": "0199a1b2-0000-7000-8000-000000000001"}

    -   name: update emits one envelope
        target_processors: '/pipeline/processors'
        input_batch:
            -   content: '{"before":null,"after":{"id":"0199a1b2-0000-7000-8000-000000000001","email":"b@example.com"},"source":{"name":"app","schema":"public","table":"customer"},"op":"u"}'
        output_batches:
            -   -   metadata_equals:
                        type: 'App\Message\CustomerChangedMessage'
                    json_equals: {"customerId": "0199a1b2-0000-7000-8000-000000000001", "email": "b@example.com"}

    -   name: delete is dropped
        target_processors: '/pipeline/processors'
        input_batch:
            -   content: '{"before":{"id":"0199a1b2-0000-7000-8000-000000000001"},"after":null,"source":{"name":"app","schema":"public","table":"customer"},"op":"d"}'
        output_batches: []

    -   name: other source is dropped
        target_processors: '/pipeline/processors'
        input_batch:
            -   content: '{"before":null,"after":{"id":"x"},"source":{"name":"other","schema":"public","table":"customer"},"op":"c"}'
        output_batches: []

    -   name: tombstone is dropped
        target_processors: '/pipeline/processors'
        input_batch:
            -   content: ''
        output_batches: []
```

Chạy test (không cần Kafka hay RabbitMQ):

```bash
docker run --rm -v "$PWD:/w" -w /w docker.redpanda.com/redpandadata/connect:4.67.5 \
    test -t './redpanda-connect/template/*.yaml' \
         -r './redpanda-connect/resource/app/*.yaml' \
         ./redpanda-connect/app.yaml

docker run --rm -v "$PWD:/w" -w /w docker.redpanda.com/redpandadata/connect:4.67.5 \
    lint -t './redpanda-connect/template/*.yaml' \
         -r ./redpanda-connect/resource/app/customer.yaml \
         ./redpanda-connect/app.yaml
```

## 7. Symfony Messenger

### 7.1 Package

```bash
composer require symfony/messenger symfony/amqp-messenger symfony/serializer \
    symfony/property-access symfony/uid
```

PHP cần `ext-amqp`. `symfony/uid` và `symfony/serializer` cho phép chuỗi uuid trong body được denormalize thành `Uuid`.

### 7.2 `config/packages/messenger.yaml`

```yaml
framework:
    messenger:
        failure_transport: failed
        buses:
            messenger.bus.default:
                middleware:
                    - doctrine_transaction   # nếu dùng Doctrine: handler được bọc transaction và tự flush

        transports:
            cdc:
                dsn: '%env(MESSENGER_TRANSPORT_DSN_PREFIX)%cdc'
                serializer: messenger.transport.symfony_serializer
            failed: 'doctrine://default?queue_name=failed'

        # Không khai báo routing cho transport cdc: PHP không bao giờ gửi vào đây.
```

`.env`:

```dotenv
MESSENGER_TRANSPORT_DSN_PREFIX=amqp://guest:guest@rabbitmq:5672/%2f/app_
```

- **Transport `failed` cần Doctrine** (`symfony/doctrine-messenger`). Project test không có Doctrine thì đổi `failed` sang một DSN AMQP khác, hoặc bỏ `failure_transport`, và bỏ middleware `doctrine_transaction`.
- **Serializer JSON bắt buộc.** Transport `cdc` phải dùng `symfony_serializer`, vì redpanda không tạo được payload PhpSerializer.
- **Tên exchange và queue.** Với DSN `…/%2f/app_cdc`, Symfony tạo exchange fanout `app_cdc` và queue cùng tên, bind với key rỗng.

### 7.3 Message

```php
<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

final readonly class CustomerChangedMessage
{
    public function __construct(
        public Uuid $customerId,
        public ?string $email = null,
    ) {
    }
}
```

```php
<?php

namespace App\Message;

use Symfony\Component\Uid\Uuid;

final readonly class WelcomeCustomerMessage
{
    public function __construct(
        public Uuid $customerId,
    ) {
    }
}
```

### 7.4 Handler

```php
<?php

namespace App\MessageHandler;

use App\Message\CustomerChangedMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CustomerChangedMessageHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(CustomerChangedMessage $message): void
    {
        $this->logger->info('customer changed', [
            'id' => (string) $message->customerId,
            'email' => $message->email,
        ]);
    }
}
```

### 7.5 Worker

```bash
php bin/console messenger:setup-transports cdc   # tạo exchange + queue + binding trước khi bật redpanda
php bin/console messenger:consume cdc -vv
```

Envelope từ redpanda không có `BusNameStamp`, nên worker dùng bus mặc định. Nếu có nhiều bus, chọn bus bằng `--bus=<tên>`; repo gốc dùng `--bus=workers_bus` để có `doctrine_transaction`.

### 7.6 Contract test phía PHP

Test này bắt lỗi decode trước khi lên môi trường thật (xem mục 11, ý 2). Sau đây là phác thảo PHPUnit dùng serializer thật của container:

```php
public function testCustomerChangedBodyDecodes(): void
{
    $serializer = static::getContainer()->get('messenger.transport.symfony_serializer');

    $envelope = $serializer->decode([
        'body' => '{"customerId":"0199a1b2-0000-7000-8000-000000000001","email":"a@example.com"}',
        'headers' => ['type' => CustomerChangedMessage::class],
    ]);

    $message = $envelope->getMessage();
    self::assertInstanceOf(CustomerChangedMessage::class, $message);
    self::assertSame('a@example.com', $message->email);
}
```

Nên dùng chính JSON mà unit test Bloblang (mục 6.5) mong đợi, để hai phía dựa trên cùng một hợp đồng.

## 8. Thứ tự khởi động

1. `postgres` (chạy `init.sql` tạo bảng), `kafka`, `rabbitmq`.
2. `connect`, rồi `register-connector`. Snapshot `initial` tạo topic `app.public.customer`.
3. `worker`: chạy `messenger:setup-transports cdc` hoặc để worker tự setup khi khởi động.
4. `redpanda-connect` bật **sau cùng**.

Bật sai thứ tự sẽ gặp hai lỗi:
- Topic chưa tồn tại khi redpanda khởi động với `start_offset: latest`: event đầu tiên bị bỏ qua.
- Exchange chưa có: publish lỗi và pipeline đứng cho tới khi worker dựng xong exchange.

## 9. Kiểm tra end-to-end

```bash
# connector đang chạy?
docker compose exec connect curl -s localhost:8083/connectors/app/status

# topic đã có?
docker compose exec kafka /opt/kafka/bin/kafka-topics.sh --bootstrap-server localhost:9092 --list

# insert một dòng (bảng đã được tạo bởi init.sql)
docker compose exec postgres psql -U app -d app -c \
  "insert into customer values ('0199a1b2-0000-7000-8000-000000000001', 'a@example.com');"

# redpanda đã đọc tới đâu?
docker compose exec kafka /opt/kafka/bin/kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 --describe --group app.cdc

# log worker: phải thấy "customer changed"
docker compose logs -f worker
```

Có thể xem queue `app_cdc` tại RabbitMQ UI `http://localhost:15672` (guest/guest).

Nếu connector báo `FAILED` sau khi DB khởi động lại, restart task bằng lệnh:

```bash
docker compose exec connect curl -s -XPOST localhost:8083/connectors/app/tasks/0/restart
```

## 10. Hợp đồng giữa Redpanda Connect và Symfony

| Phía redpanda | Phía Symfony | Lệch thì sao |
|---|---|---|
| `meta type` = FQCN | header `type` → class để deserialize | Class không tồn tại: lỗi decode |
| key trong `body` | tên tham số constructor | Thiếu tham số bắt buộc: lỗi decode |
| chuỗi uuid | kiểu `Uuid` (UidNormalizer) | Uuid sai định dạng: lỗi decode |
| exchange = đoạn cuối của prefix + transport | DSN = prefix + transport | Lệch tên: publish lỗi, pipeline đứng |
| `content_type: application/json` | `symfony_serializer` (format json) | Dùng PhpSerializer: lỗi decode |

## 11. Điểm dễ vấp (rút ra từ repo gốc)

1. **Retry vô hạn làm tắc cả pipeline.** Repo gốc bọc toàn bộ output trong `retry max_retries: 0`, nên một event lỗi vĩnh viễn (mapping lỗi, tombstone) giữ offset mãi mãi, và restart cũng không gỡ được. Blueprint này tách riêng: event `errored()` được log rồi drop; chỉ publish AMQP được retry vô hạn. Muốn không mất event lỗi thì thay `drop: {}` bằng output ghi vào một topic DLQ.
2. **Decode lỗi ở PHP thì message mất luôn.** Sai `type` hay thiếu tham số: Symfony nack mà không requeue, message không vào `failed`, và process `messenger:consume` thoát. Chặn bằng unit test Bloblang (6.5) cộng contract test PHP (7.6).
3. **`start_offset: latest`**: tạo topic trước rồi mới bật redpanda.
4. **`exchange_declare: false`**: setup transport trước khi publish.
5. **Transport theo pipeline, không theo routing.** Message luôn vào transport mà pipeline chỉ định. `DelayStamp` không qua được hop này.
6. **`before` null** khi REPLICA IDENTITY là DEFAULT. Nhánh nào cần so `before`/`after` thì bảng nguồn phải bật FULL.
7. **Timestamp.** Ở chế độ `adaptive` mặc định, cột `timestamp(0..3)` đến dưới dạng epoch **mili giây**, `timestamp(4..6)` hoặc `timestamp` không có precision là **micro giây**, còn `timestamptz` là chuỗi ISO. Repo gốc từng chia nhầm cho 1e6, làm mọi dòng mang ngày 1970. Nên test giá trị cụ thể trong unit test.
8. **Một topic chỉ cho một pipeline.** Hai pipeline cùng đọc một bảng sẽ publish mỗi event hai lần.
9. **Mỗi pipeline một consumer group riêng.** Dùng chung group thì hai pipeline sẽ chia partition của nhau.
10. **Thứ tự.** `threads: 1` và `max_in_flight: 1` chỉ giữ được thứ tự khi topic có một partition, hoặc event cùng key vào cùng partition.

## 12. Khác biệt so với repo gốc

| | Repo gốc (`gci-business.yaml`) | Blueprint |
|---|---|---|
| Output khi mapping lỗi | `reject` bên trong retry vô hạn, nên pipeline đứng | log ERROR rồi `drop` (hoặc DLQ) |
| Chạy redpanda-connect | binary cài vào image PHP cli | image chính thức `redpandadata/connect:4.67.5` |
| Transport | 6 transport JSON, mỗi pipeline một cái | 1 transport `cdc` |
| Unit test Bloblang | test so sánh với listener PHP cũ (`*PipelineEquivalenceTest`) | `app_benthos_test.yaml` chạy bằng `redpanda-connect test` |
| Debezium local | `quay.io/debezium/connect:2.0` | giữ nguyên; bản mới hơn thêm `signal.enabled.channels` và các tuỳ chọn khác |

## 13. Áp vào symfony-frankenphp

Đã dựng và chạy end-to-end ngày 24/09/2026. Các case đã chạy thật:
- `c` sinh 2 message, `u` sinh 1, `d` bị drop.
- Redpanda tự kết nối lại sau khi RabbitMQ bị recreate.
- Message không có handler được retry 3 lần rồi vào `failed`.
- `register-connector` chạy lần hai vẫn exit 0.
- Cold start từ volume rỗng thì không service nào phải restart.

```bash
docker compose --profile cdc up -d        # không có --profile thì stack giữ nguyên như cũ
docker compose exec connect curl -s localhost:8083/connectors/app/status   # chờ RUNNING rồi mới test
docker compose exec database psql -U app -d app -c \
  "insert into customer values ('0199a1b2-0000-7000-8000-000000000001', 'a@example.com');"
docker compose logs -f worker-cdc          # "customer changed" + "welcome customer"
```

| Blueprint | Repo này | Lý do |
|---|---|---|
| service `postgres`, bảng từ `init.sql` | service `database`; bảng từ Entity `Customer` + migration, service one-shot `migrate` chạy trước `register-connector` | `initdb.d` không chạy lại trên volume đã init; bảng lạ trong `public` làm `doctrine:schema:validate` đỏ trên CI |
| Debezium `connect:2.0`, password trong JSON | `connect:2.7.3.Final`, JSON dùng `${env:POSTGRES_PASSWORD}` qua `EnvVarConfigProvider` | Repo chạy PG16. Credential chỉ nằm trong compose |
| `POST /connectors` | `PUT /connectors/app/config` | Idempotent: `up` lần hai không bị 409 |
| Nhớ thứ tự khởi động (mục 8) | `depends_on` + one-shot `migrate`, `kafka-init` (tạo topic 1 partition) | Không phụ thuộc trí nhớ của người chạy |
| guest/guest, DSN lặp ở hai nơi | `MESSENGER_TRANSPORT_DSN_PREFIX` trong `.env`; compose đọc lại chính biến đó cho redpanda-connect | Một nguồn duy nhất cho tên exchange và credential |
| bus `messenger.bus.default` | `workers_bus` có sẵn (bus duy nhất nên là mặc định, đã có `doctrine_transaction`) | Không cần `--bus` |
| chỉ có handler cho `CustomerChangedMessage` | thêm `WelcomeCustomerMessageHandler` | Thiếu handler thì message retry hết lượt rồi nằm trong `failed` |
| contract test chép JSON (7.6) | `tests/Messenger/CdcContractTest.php` đọc thẳng `app_benthos_test.yaml`, decode rồi encode ngược lại | Một nguồn hợp đồng; bắt được cả key sai tên bị bỏ qua âm thầm |
| Kafka có 4 listener, port publish ra host | 2 listener; không service CDC nào publish port | Host thường đã có stack khác giữ 9092 |

Điểm vấp gặp khi dựng, không có trong blueprint:
- **Dòng ghi trước khi connector chạy thuộc snapshot.** `up -d` trả về trước khi `register-connector` xong. Insert trong khoảng đó ra event op `r` và bị drop, đúng thiết kế. Phải chờ status `RUNNING` rồi mới test.
- **`symfony/doctrine-messenger` chưa từng được cài**, dù `failure_transport: failed` là `doctrine://`. Message hết retry làm worker crash thay vì vào `failed`. Lỗi này có từ trước và ảnh hưởng cả transport `common`; đã cài package.
- **Kéo theo việc bảng `messenger_messages` phải được tạo bằng migration.** Package trên đưa bảng này vào schema mà Doctrine mong đợi. Nếu `schema_filter` vẫn loại nó ra thì hai lệnh bất đồng: `schema:validate` đòi bảng phải có, còn `migrations:diff` không bao giờ sinh lệnh tạo bảng, nên CI đỏ trên DB mới. Đã bỏ phần loại trừ đó khỏi filter và thêm migration.
- **Hasura có trong stack thì `migrations:diff` luôn sinh `CREATE SCHEMA hdb_catalog` thừa trong `down()`.** Muốn kiểm tra drift thì dùng `schema:validate` và `schema:update --dump-sql`.
- **RabbitMQ "started" chưa nhận kết nối.** Worker crash "Could not connect to the AMQP server" rồi restart vài lần. Đã thêm healthcheck `check_port_connectivity`.
- **Image FrankenPHP có HEALTHCHECK `:2019`.** Worker CLI không mở port này nên bị báo `unhealthy`. Anchor `x-php-cli` đã tắt healthcheck cho các service CLI.
- **`SerializedMessageStamp`.** `decode()` gắn stamp này, nên `encode()` trả lại nguyên văn body gốc. Contract test phải bỏ stamp trước khi encode, không thì assertion round-trip luôn xanh.
- **Replication slot giữ WAL khi stack `cdc` tắt.** `max_slot_wal_keep_size=1GB` đặt trần cho lượng WAL này. Vượt trần thì slot bị huỷ, và phải drop slot rồi snapshot lại.

### UI giám sát (profile `cdc`)

| UI | URL | Xem được gì |
|---|---|---|
| Redpanda Console v3.12.0 | http://localhost:8091 | Status từng task của connector `app` (có Restart/Pause). Consumer group `app.cdc`: `Stable` + 1 member là redpanda-connect còn sống; `Empty` là đã chết; lag không giảm là đang kẹt. Đọc được message trong topic. |
| Debezium UI 2.5 (**archived**) | http://localhost:8889 | Danh sách connector và task |

Đã thử bằng cách làm hỏng từng thành phần: task `FAILED`, redpanda-connect chết. Cả hai UI đều báo được, kèm các bẫy sau:
- **Trang danh sách vẫn báo connector xanh "Running" khi task đã `FAILED`.** Phải nhìn cột Tasks: Console hiện ⚠️ `0 / 1`, Debezium UI hiện `FAILED : 1`. Trang chi tiết connector của Console thì báo rõ "Unhealthy".
- **Console v3 đổi khoá `connect:` thành `kafkaConnect:`.** Env kiểu v2 (`CONNECT_ENABLED`, `CONNECT_CLUSTERS_*`) bị bỏ qua mà không báo lỗi: đã chạy thử và nhận `isConfigured: false`. Stack gci đang dùng đúng kiểu cấu hình này với `console:latest`. Image trên máy được build ngày 16/09/2026, trùng ngày ra v3.12.0, nên nhiều khả năng tab Connect bên đó đang trống. Chưa kiểm trực tiếp được vì lúc đó Console của gci không chạy.
- **Debezium UI đọc `KAFKA_CONNECT_URIS`, không phải `KAFKA_CONNECT_CLUSTERS` như README ghi.** Sai tên thì nó lặng lẽ gọi `localhost:8083`. UI này cũng cần `ENABLE_DEBEZIUM_KC_REST_EXTENSION=true` bên service `connect`.
- **Port cố ý khác 8090/8888 của stack gci.** Hai version khác nhau trên cùng một origin thì trình duyệt trộn cache, giống vụ RabbitMQ UI trắng trang.

Chưa làm: manifest k8s (`devops/`) cho Kafka, Debezium, redpanda-connect và worker `cdc`.
