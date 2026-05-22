-- Track whether the QR/ticket receipt email was successfully sent.
ALTER TABLE purchases
  ADD COLUMN receipt_email_sent_at TIMESTAMP NULL AFTER provider_reference,
  ADD COLUMN receipt_email_error VARCHAR(255) NULL AFTER receipt_email_sent_at;

