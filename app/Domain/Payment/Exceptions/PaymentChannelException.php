<?php

declare(strict_types=1);

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/**
 * 支付通道异常（通道不可用/配置缺失/载荷不可解析）。
 */
class PaymentChannelException extends RuntimeException {}
