<?php

namespace App\Domain\Communications\Transport;

use App\Domain\Communications\MailPreviewBox;
use App\Domain\Communications\RecipientMask;
use App\Domain\Communications\TransactionalMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Driver "log" (6.1): al posto del trasporto "log" di Laravel, che scriverebbe nel log l'intero
 * messaggio con indirizzo e testi dell'ospite (vietato da 08 e 09), registra solo tipo, destinatario
 * mascherato e Message-ID. Con APP_ENV=local il messaggio completo va nella casella di anteprima
 * (/dev/mail), file locali fuori dai log.
 */
final class PreviewLogTransport extends AbstractTransport
{
    public function __construct(private readonly LoggerInterface $logger, private readonly MailPreviewBox $box)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new TransportException('Messaggio non supportato.');
        }

        $email = MessageConverter::toEmail($original);
        $category = $email->getHeaders()->get(TransactionalMailer::CATEGORY_HEADER)?->getBodyAsString();

        $this->logger->info('mail.sent', [
            'category' => $category,
            'to' => array_map(fn (Address $address): string => RecipientMask::email($address->getAddress()), $email->getTo()),
            'message_id' => $message->getMessageId(),
        ]);

        if ($this->box->enabled()) {
            $this->box->store($email, $category);
        }
    }

    public function __toString(): string
    {
        return 'preview-log';
    }
}
