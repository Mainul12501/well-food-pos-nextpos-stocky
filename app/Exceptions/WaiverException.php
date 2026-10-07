<?php

namespace App\Exceptions;

use RuntimeException;

class WaiverException extends RuntimeException
{
    // A refused waiver operation is an expected business outcome, not an application error
    public function report()
    {
        return true;
    }

    public function render($request)
    {
        return response()->json(['success' => false, 'message' => $this->getMessage()], 422);
    }
}
