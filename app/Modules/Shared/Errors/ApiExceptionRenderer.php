<?php

namespace App\Modules\Shared\Errors;

use App\Modules\Shared\Http\Responses\ErrorResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns every exception thrown under /api into the standard error body, so no
 * endpoint can return a bespoke error shape or leak a stack trace.
 */
final class ApiExceptionRenderer
{
    public function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            $exception instanceof DomainError => ErrorResponse::make(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->httpStatus,
                $exception->details,
            ),
            $exception instanceof ValidationException => ErrorResponse::make(
                ErrorCode::VALIDATION_ERROR,
                'One or more fields are invalid.',
                422,
                $this->validationDetails($exception),
            ),
            $exception instanceof AuthenticationException => ErrorResponse::make(
                ErrorCode::UNAUTHENTICATED,
                'Authentication is required.',
                401,
            ),
            $exception instanceof AuthorizationException,
            $exception instanceof AccessDeniedHttpException => ErrorResponse::make(
                ErrorCode::FORBIDDEN,
                'You do not have permission to perform this action.',
                403,
            ),
            // Rows outside the caller's scope also land here, by design (spec §4.4):
            // the response must not reveal whether the row exists.
            $exception instanceof ModelNotFoundException,
            $exception instanceof NotFoundHttpException => ErrorResponse::make(
                ErrorCode::NOT_FOUND,
                'The requested resource was not found.',
                404,
            ),
            $exception instanceof MethodNotAllowedHttpException => ErrorResponse::make(
                ErrorCode::METHOD_NOT_ALLOWED,
                'This method is not allowed on this resource.',
                405,
                headers: $exception->getHeaders(),
            ),
            $exception instanceof ThrottleRequestsException => ErrorResponse::make(
                ErrorCode::RATE_LIMITED,
                'Too many requests. Try again later.',
                429,
                headers: $exception->getHeaders(),
            ),
            $exception instanceof HttpExceptionInterface => $this->renderHttpException($exception),
            default => ErrorResponse::make(
                ErrorCode::INTERNAL_ERROR,
                'An unexpected error occurred.',
                500,
            ),
        };
    }

    /**
     * @return list<array{field: string, issue: string}>
     */
    private function validationDetails(ValidationException $exception): array
    {
        $details = [];

        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $details[] = ['field' => $field, 'issue' => $message];
            }
        }

        return $details;
    }

    private function renderHttpException(HttpExceptionInterface $exception): JsonResponse
    {
        $status = $exception->getStatusCode();

        $code = match ($status) {
            400 => ErrorCode::MALFORMED_REQUEST,
            409 => ErrorCode::CONFLICT,
            412 => ErrorCode::PRECONDITION_FAILED,
            428 => ErrorCode::PRECONDITION_REQUIRED,
            503 => ErrorCode::SERVICE_UNAVAILABLE,
            default => $status >= 500 ? ErrorCode::INTERNAL_ERROR : ErrorCode::MALFORMED_REQUEST,
        };

        $message = match (true) {
            $status === 503 => 'The service is temporarily unavailable.',
            $status >= 500 => 'An unexpected error occurred.',
            $exception->getMessage() !== '' => $exception->getMessage(),
            default => 'The request could not be processed.',
        };

        return ErrorResponse::make($code, $message, $status, headers: $exception->getHeaders());
    }
}
