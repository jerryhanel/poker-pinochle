<?php
require __DIR__ . '/_boot.php';

// GET — who am I? Also hands the client its CSRF token.
$u = pp_current_user();
if ($u === null) {
    pp_api_ok(['signedIn' => false]);
}
pp_api_ok([
    'signedIn' => true,
    'uuid'     => $u['uuid'],
    'name'     => $u['name'],
    'csrf'     => pp_csrf_token(),
]);
