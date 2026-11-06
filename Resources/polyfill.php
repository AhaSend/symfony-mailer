<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

// TrackingHeader was added to symfony/mailer in Symfony 8.2. The bridge's
// transports use it, so it is provided here for earlier versions.
if (!class_exists(Symfony\Component\Mailer\Header\TrackingHeader::class)) {
    require __DIR__.'/polyfill/TrackingHeader.php';
}
