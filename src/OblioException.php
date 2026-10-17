<?php
declare(strict_types=1);
namespace Zion;

/** Stable error codes only; never persist raw provider bodies or credentials. */
final class OblioException extends \RuntimeException {}
