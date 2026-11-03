<?php

declare(strict_types=1);

namespace App\Shared\Http\Problems;

/**
 * Error catalog of the REST API (contracts/errors.md, schema ProblemCode).
 * Each code has a fixed HTTP status, English title and default Arabic detail.
 */
enum ProblemCode: string
{
    case MalformedRequest = 'MALFORMED_REQUEST';
    case Unauthenticated = 'UNAUTHENTICATED';
    case TokenExpired = 'TOKEN_EXPIRED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case RefreshTokenInvalid = 'REFRESH_TOKEN_INVALID';
    case Forbidden = 'FORBIDDEN';
    case NotFound = 'NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case InsufficientStock = 'INSUFFICIENT_STOCK';
    case OrderInvalidTransition = 'ORDER_INVALID_TRANSITION';
    case PayloadTooLarge = 'PAYLOAD_TOO_LARGE';
    case UnsupportedMediaType = 'UNSUPPORTED_MEDIA_TYPE';
    case ValidationFailed = 'VALIDATION_FAILED';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case RateLimited = 'RATE_LIMITED';
    case InternalError = 'INTERNAL_ERROR';
    case InventoryUnavailable = 'INVENTORY_UNAVAILABLE';
    case InventoryTimeout = 'INVENTORY_TIMEOUT';

    private const string TYPE_BASE = 'https://ecommerce.local/problems/';

    public function status(): int
    {
        return match ($this) {
            self::MalformedRequest => 400,
            self::Unauthenticated, self::TokenExpired, self::InvalidCredentials, self::RefreshTokenInvalid => 401,
            self::Forbidden => 403,
            self::NotFound => 404,
            self::MethodNotAllowed => 405,
            self::InsufficientStock, self::OrderInvalidTransition => 409,
            self::PayloadTooLarge => 413,
            self::UnsupportedMediaType => 415,
            self::ValidationFailed, self::IdempotencyKeyReused => 422,
            self::RateLimited => 429,
            self::InternalError => 500,
            self::InventoryUnavailable, self::InventoryTimeout => 503,
        };
    }

    public function type(): string
    {
        return self::TYPE_BASE.str_replace('_', '-', strtolower($this->value));
    }

    public function title(): string
    {
        return match ($this) {
            self::MalformedRequest => 'Malformed request',
            self::Unauthenticated => 'Unauthenticated',
            self::TokenExpired => 'Token expired',
            self::InvalidCredentials => 'Invalid credentials',
            self::RefreshTokenInvalid => 'Refresh token invalid',
            self::Forbidden => 'Forbidden',
            self::NotFound => 'Not found',
            self::MethodNotAllowed => 'Method not allowed',
            self::InsufficientStock => 'Insufficient stock',
            self::OrderInvalidTransition => 'Invalid order status transition',
            self::PayloadTooLarge => 'Payload too large',
            self::UnsupportedMediaType => 'Unsupported media type',
            self::ValidationFailed => 'Validation failed',
            self::IdempotencyKeyReused => 'Idempotency key reused',
            self::RateLimited => 'Too many requests',
            self::InternalError => 'Internal server error',
            self::InventoryUnavailable => 'Inventory unavailable',
            self::InventoryTimeout => 'Inventory timeout',
        };
    }

    public function defaultDetail(): string
    {
        return match ($this) {
            self::MalformedRequest => 'جسم الطلب ليس JSON صالحاً.',
            self::Unauthenticated => 'يجب تسجيل الدخول للوصول إلى هذا المورد.',
            self::TokenExpired => 'انتهت صلاحية رمز الدخول.',
            self::InvalidCredentials => 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
            self::RefreshTokenInvalid => 'انتهت الجلسة، يرجى تسجيل الدخول من جديد.',
            self::Forbidden => 'ليس لديك صلاحية لتنفيذ هذه العملية.',
            self::NotFound => 'المورد المطلوب غير موجود.',
            self::MethodNotAllowed => 'طريقة الطلب غير مدعومة لهذا المسار.',
            self::InsufficientStock => 'الكمية المتوفرة غير كافية لبعض المنتجات.',
            self::OrderInvalidTransition => 'لا يمكن نقل الطلب إلى هذه الحالة.',
            self::PayloadTooLarge => 'حجم الطلب أكبر من المسموح.',
            self::UnsupportedMediaType => 'نوع المحتوى غير مدعوم.',
            self::ValidationFailed => 'البيانات المرسلة غير صالحة.',
            self::IdempotencyKeyReused => 'استُخدم مفتاح التكرار نفسه مع طلب مختلف.',
            self::RateLimited => 'محاولات كثيرة، حاول لاحقاً.',
            self::InternalError => 'حدث خطأ غير متوقع، حاول لاحقاً.',
            self::InventoryUnavailable => 'خدمة المخزون غير متاحة حالياً، حاول بعد قليل.',
            self::InventoryTimeout => 'خدمة المخزون لم تستجب في الوقت المحدد، حاول بعد قليل.',
        };
    }
}
