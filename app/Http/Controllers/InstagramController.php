<?php
namespace App\Http\Controllers;

use App\Services\InstagramService;
use Illuminate\Http\Request;

class InstagramController extends Controller
{
    protected $instagramService;

    public function __construct()
    {
        $this->instagramService = new InstagramService();
    }

    public function webhook(Request $request)
    {
        if ($request->isMethod('get')) {
            $challenge = $this->instagramService->verifyWebhook(
                $request->query('hub_mode'),
                $request->query('hub_verify_token'),
                $request->query('hub_challenge')
            );
            
            return $challenge ? response($challenge) : response('Forbidden', 403);
        }

        $this->instagramService->processWebhook($request->all());
        return response()->json(['status' => 'success']);
    }
}
