<?php
/**
 * Security Headers MU-Plugin
 *
 * Adds recommended HTTP security headers to every response.
 *
 * @package MerryVic
 */

add_action( 'send_headers', function () {
    // Enforce HTTPS (HSTS) – change max-age as appropriate for your environment.
    header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload' );

    // Content Security Policy – restrict resources to self by default.
    // Adjust script-src, style-src, img-src etc. if external resources are needed.
    header( "Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:;" );

    // Prevent MIME type sniffing.
    header( 'X-Content-Type-Options: nosniff' );

    // Referrer Policy – more privacy‑preserving.
    header( 'Referrer-Policy: strict-origin-when-cross-origin' );

    // Click‑jacking protection.
    header( 'X-Frame-Options: SAMEORIGIN' );

    // Legacy XSS protection header.
    header( 'X-XSS-Protection: 1; mode=block' );
} );
