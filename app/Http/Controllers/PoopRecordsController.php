<?php

namespace App\Http\Controllers;

use App\Services\PoopService;
use Illuminate\Http\Request;
use LINE\Constants\HTTPHeader;
class PoopRecordsController extends Controller
{
    /**
     */
    public function webhook(Request $request)
    {
        if (!$request->hasHeader(HTTPHeader::LINE_SIGNATURE)) {
            abort(400, 'Signature not found');
        }

        $lbs = new PoopService();

        $lbs->handleMessage($request);

        return response()->noContent(200);
    }
}
