<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * El conjunto de filas bloqueadas al inicio de una transacción quedó desactualizado
 * respecto del estado real de la entidad ya bloqueada (D-03).
 *
 * Se usa para abortar la transacción y recalcular el plan de bloqueos fuera de ella,
 * preservando el orden canónico CashShift -> Customer -> Sale -> Product en MySQL/InnoDB.
 * No extiende InvalidArgumentException para no mapearse a HTTP 422 por accidente.
 */
class StaleLockSetException extends RuntimeException {}
