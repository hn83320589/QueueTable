<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 業務規則違反。訊息是給使用者看的中文，HTTP 層會轉成表單錯誤或 422。
 */
abstract class QueueTableException extends RuntimeException {}
