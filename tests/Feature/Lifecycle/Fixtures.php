<?php

namespace Lantern\Tests\Feature\Lifecycle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Controller;

class StoreThingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['title' => ['required', 'string']];
    }
}

class ThingController extends Controller
{
    public function index()
    {
        return view('hello', ['name' => 'world']);
    }

    public function store(StoreThingRequest $request)
    {
        return response()->json(['ok' => true], 201);
    }
}

class InvokableController extends Controller
{
    public function __invoke()
    {
        return 'invoked';
    }
}
