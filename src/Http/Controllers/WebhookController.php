<?php

declare(strict_types=1);

namespace Voxyfy\AnadoluPay\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;
use Voxyfy\AnadoluPay\AnadoluPay;
use Voxyfy\AnadoluPay\Contracts\ProvidesWebhookAcknowledgement;
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
     * Yanıt biçimi sağlayıcıya göre değişir: çoğu sağlayıcı için JSON
     * yeterlidir, ancak bazıları belirli bir gövde bekler ve almazsa
     * bildirimi tekrar tekrar gönderir (örn. PayTR düz metin `OK`).
     * Bu driver'lar `ProvidesWebhookAcknowledgement` uygular.
     *
     * @param  Request  $request  Gelen HTTP isteği
     * @param  string  $driver  Ödeme sağlayıcısı driver adı (örn: 'iyzico', 'paytr')
     */
    public function handle(Request $request, string $driver): SymfonyResponse
    {
        $gateway = null;

        try {
            $gateway = app(AnadoluPay::class)->driver($driver);

            $verifyData = new VerifyPaymentData(
                payload: $request->all(),
                headers: $request->headers->all(),
                rawBody: $request->getContent(),
            );

            $gateway->verify($verifyData);

            return $this->acknowledge($gateway, handled: true);

        } catch (DriverNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 404);

        } catch (InvalidSignatureException $e) {
            // İmza tutmayan bildirim onaylanmaz: sahte olabilir.
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);

        } catch (PaymentFailedException $e) {
            return $this->acknowledge($gateway, handled: false, fallback: [
                'success' => false,
                'message' => $e->getMessage(),
            ], status: 400);

        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sağlayıcının beklediği onay yanıtını üretir.
     *
     * Driver özel bir gövde istemiyorsa JSON döneriz.
     *
     * @param  array<string, mixed>  $fallback  JSON dönülecekse gövde
     */
    protected function acknowledge(
        ?object $gateway,
        bool $handled,
        array $fallback = ['success' => true],
        int $status = 200,
    ): SymfonyResponse {
        if ($gateway instanceof ProvidesWebhookAcknowledgement) {
            return new Response(
                content: $gateway->webhookAcknowledgement($handled),
                status: $status,
                headers: ['Content-Type' => $gateway->webhookAcknowledgementContentType()],
            );
        }

        return response()->json($fallback, $status);
    }
}
