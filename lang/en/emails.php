<?php

// Booking transition emails (03). Real sending from M6; locally the "log" mailer.
return [
    'greeting' => 'Dear guest,',
    'signature' => 'The :property team',
    'confirmed' => [
        'subject' => 'Booking confirmed :code',
        'lines' => ['Booking :code for :unit from :check_in to :check_out is confirmed.', 'Total for the stay: :amount.'],
    ],
    'awaiting_approval' => [
        'subject' => 'Request received :code',
        'lines' => ['We received request :code for :unit from :check_in to :check_out.', 'The amount is only authorised: it will be charged if the property confirms.'],
    ],
    'approval_requested' => [
        'subject' => 'New request to approve :code',
        'lines' => ['New request :code for :unit from :check_in to :check_out.', 'Check the Booking and Airbnb calendars before approving.'],
    ],
    'expired' => [
        'subject' => 'Booking not completed :code',
        'lines' => ['The payment for booking :code was not completed in time and the dates have been released.'],
    ],
    'payment_failed' => [
        'subject' => 'Payment failed :code',
        'lines' => ['The payment for booking :code failed and the dates have been released.'],
    ],
    'late_payment' => [
        'subject' => 'Late payment :code',
        'lines' => ['The payment for booking :code arrived after the deadline, when the dates were no longer held.', 'We apologise: the amount will be returned to you automatically.'],
    ],
    'rejected' => [
        'subject' => 'Request not accepted :code',
        'lines' => ['The property could not accept request :code. No amount has been charged.'],
    ],
    'approval_expired' => [
        'subject' => 'Request expired :code',
        'lines' => ['Request :code expired without an answer from the property. No amount has been charged.'],
    ],
    'approval_expired_manager' => [
        'subject' => 'Request expired without an answer :code',
        'lines' => ['Request :code for :unit from :check_in to :check_out has expired and the dates have been released.'],
    ],
    'capture_failed' => [
        'subject' => 'URGENT: charge failed :code',
        'lines' => ['The charge for booking :code failed. The dates stay held for 24 hours.', 'Send the guest a payment link or release the dates.'],
    ],
    'recovery_link' => [
        'subject' => 'Complete your payment :code',
        'lines' => ['The payment for booking :code did not go through. You can complete it from the booking page.'],
    ],
    'dates_released' => [
        'subject' => 'Booking cancelled :code',
        'lines' => ['The payment for booking :code was not completed and the dates have been released.'],
    ],
    'cancelled' => [
        'subject' => 'Booking cancelled :code',
        'lines' => ['Booking :code has been cancelled.', 'Refund started: :amount. We will write again when it is completed.'],
    ],
    'refund_completed' => [
        'subject' => 'Refund completed :code',
        'lines' => ['The refund of :amount for booking :code has been completed.'],
    ],
    'dispute_opened' => [
        'subject' => 'URGENT: payment disputed :code',
        'lines' => ['The guest disputed the payment for booking :code with their bank.', 'Respond to the dispute from your Stripe dashboard before the deadline set by Stripe. The booking status does not change.'],
    ],
    'refund_failed' => [
        'subject' => 'Refund failed :code',
        'lines' => ['The refund of :amount for booking :code failed after three automatic attempts.', 'From the panel you can retry it or mark it as refunded outside the platform (for example by bank transfer).'],
    ],
    'refund_reversed' => [
        'subject' => 'Urgent: refund not completed :code',
        'lines' => ['The refund of :amount to :guest for booking :code did not go through: Stripe reported it after marking it as succeeded. Please contact the guest.', 'The guest has not received any automatic notice. From the panel you can retry the refund or mark it as refunded outside the platform (for example by bank transfer).'],
    ],
    'capture_failed_reminder' => [
        'subject' => 'Reminder: payment capture failed :code',
        'lines' => ['The payment capture for booking :code is still unresolved. The dates will be released in 12 hours.', 'Send a payment link to the guest or release the dates.'],
    ],
    'calendar_conflict_opened' => [
        'subject' => 'Calendar conflict: :unit from :check_in to :check_out',
        'lines' => ['The dates from :check_in to :check_out at :unit (:property) are occupied by two different sources.', 'The dates stay blocked and no booking is cancelled automatically. Check in the panel which booking to keep.'],
    ],
    'calendar_conflict_auto_resolved' => [
        'subject' => 'Calendar conflict resolved: :unit from :check_in to :check_out',
        'lines' => ['The external event causing the conflict at :unit (:property) from :check_in to :check_out was removed from the OTA calendar.', 'The conflict was closed automatically. Other bookings on the same dates are unchanged.'],
    ],
    'calendar_sync_unhealthy' => [
        'subject' => ':source calendar not synchronised: :unit',
        'lines' => ['For more than 3 hours we have not been able to read the :source calendar of :unit (:property).', 'Dates already imported stay blocked, but new OTA bookings may not be visible. Check the connection in the panel.'],
    ],
    'quote_sent' => [
        'subject' => 'Your quote for :property',
        'lines' => ['Here is the quote for :unit from :check_in to :check_out: total :amount.', 'The quote is valid until :expires_at (UTC). You can book from the link below.'],
    ],
];
