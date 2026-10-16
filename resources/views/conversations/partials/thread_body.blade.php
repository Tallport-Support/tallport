{{-- A message's body (App\Misc\MessageBody::forThread()), its signature folded. --}}
{!! $thread_body !!}@if ($thread_signature)<x-fruit::disclosure variant="quiet" :title="__('Signature')" class="thread-signature">{!! $thread_signature !!}</x-fruit::disclosure>@endif
