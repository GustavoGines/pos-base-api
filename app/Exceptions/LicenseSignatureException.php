<?php

namespace App\Exceptions;

use Exception;

/**
 * La respuesta del servidor de licencias no trae una firma Ed25519 válida
 * o el nonce de desafío no coincide (FINDING-LIC-01, L-01, L-17).
 */
class LicenseSignatureException extends Exception
{
}
