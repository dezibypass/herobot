<?php
namespace App\Http\Controllers;

use App\Services\MessengerService;
use Illuminate\Http\Request;

class MessengerController extends Controller
{
    protected $messengerService;

    public function __construct()
    {
        $this->messengerService = new MessengerService();
    }

    public function webhook(Request $request)
    {
        if ($request->isMethod('get')) {
            $challenge = $this->messengerService->verifyWebhook(
                $request->query('hub_mode'),
                $request->query('hub_verify_token'),
                $request->query('hub_challenge')
            );
            
            return $challenge ? response($challenge) : response('Forbidden', 403);
        }

        $this->messengerService->processWebhook($request->all());
        return response()->json(['status' => 'success']);
    }
}
