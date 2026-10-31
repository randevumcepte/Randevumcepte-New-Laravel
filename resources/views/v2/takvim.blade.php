@extends('layout.panel')

@section('baslik', 'Randevu Takvimi')
@section('aktif', 'takvim-v2')

@push('stil')
    {{-- EventCalendar (vkurko/calendar) — MIT lisansli, ucretsiz; surum SABIT. --}}
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@event-calendar/build@5.16.0/dist/event-calendar.min.css">
    <link rel="stylesheet" href="/css/takvim-v2.css?v=5">
@endpush

@section('icerik')
    <div id="rc-takvim" data-salon="{{ $salonId }}"></div>
@endsection

@push('betik')
    <script src="https://cdn.jsdelivr.net/npm/@event-calendar/build@5.16.0/dist/event-calendar.min.js"></script>
    <script type="module" src="/js/takvim-v2.js?v=7"></script>
@endpush
