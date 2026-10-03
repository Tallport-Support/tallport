@php(\FruitUI\Support\ComponentContract::validate('typing', $attributes))
<p role="status" {{ $attributes->except('role')->class(['f-typing']) }}>@if($slot->isNotEmpty())<span class="f-typing__dots" aria-hidden="true"><span></span><span></span><span></span></span>{{ $slot }}@endif</p>
