<?php

$key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
echo $key, PHP_EOL;
