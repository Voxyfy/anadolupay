<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;
use Voxyfy\AnadoluPay\AnadoluPay;
use Voxyfy\AnadoluPay\DTO\VerifyPaymentData;
use Voxyfy\AnadoluPay\Exceptions\DriverNotFoundException;
use Voxyfy\AnadoluPay\Exceptions\InvalidSignatureException;
use Voxyfy\AnadoluPay\Exceptions\PaymentFailedException;

/**
 * Webhook Controller
 *
 * Ödeme sağlayıcılarından gelen callback/webhook isteklerini
 * tek bir endpoint üzerinden işler.
 */
class WebhookController
{
    /**
     * Webhook isteğini işler.
     *
     * @param  Request  $request  Gelen HTTP isteği
     * @param  string  $driver  Ödeme sağlayıcısı driver adı (örn: 'iyzico', 'paytr')
     * @return JsonResponse İşlem sonucu
     */
    public function handle(Request $request, string $driver): JsonResponse
    {
        try {
            $gateway = app(AnadoluPay::class)->driver($driver);

            $verifyData = new VerifyPaymentData(
                payload: $request->all(),
                headers: $request->headers->all(),
                rawBody: $request->getContent(),
            );

            $gateway->verify($verifyData);

            return response()->json(['success' => true]);

        } catch (DriverNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);

        } catch (InvalidSignatureException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);

        } catch (PaymentFailedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
