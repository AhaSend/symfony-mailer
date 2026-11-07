CHANGELOG
=========

ahasend/symfony-mailer 1.0
--------------------------

 * Fork the 8.2 bridge so it installs on Symfony 7.4, 8.0 and 8.1, replacing `symfony/aha-send-mailer`
 * Include `TrackingHeader`, new in Symfony 8.2, for those versions
 * API v2: send inline Content-IDs in angle brackets, as the API requires
 * API v2: do not send Bcc addresses as a header
 * API v2: move AhaSend's SMTP special headers to the API's fields
 * API v1: call `api.ahasend.com`, like v2; `send.ahasend.com` is the SMTP host

8.2
---

 * Add support for the v2 API, used when an account id is set in the DSN
 * Deprecate sending through the legacy v1 API
 * Use the `Message-ID` returned by the v2 API as the remote event id, instead of AhaSend's internal event id
 * Add a `$clock` argument to `AhaSendRequestParser`

7.3
---

 * Add the bridge
