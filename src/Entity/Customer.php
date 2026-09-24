<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

// Bảng nguồn của pipeline CDC (docker/debezium/app-connector.json).
//
// Bảng tạo bằng migration chứ KHÔNG bằng init.sql như blueprint: CI chạy
// doctrine:schema:validate, và bảng nào trong public không có entity là drift.
#[ORM\Entity]
class Customer
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: UuidType::NAME)]
        private Uuid $id,

        #[ORM\Column(type: Types::TEXT, nullable: true)]
        private ?string $email = null,
    ) {
    }
}
