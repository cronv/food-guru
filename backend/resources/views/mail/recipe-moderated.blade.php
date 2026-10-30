@component('mail::message')
# Здравствуйте, {{ $name }}!

@if ($published)
Ваш рецепт «{{ $title }}» прошёл проверку и опубликован в каталоге. Спасибо, что поделились!

За публикацию в ваш рейтинг начислены баллы.

@component('mail::button', ['url' => $url, 'color' => 'success'])
Открыть рецепт
@endcomponent
@else
Редакция вернула рецепт «{{ $title }}» на доработку.

@if ($note)
**Что поправить:** {{ $note }}
@endif

Исправьте рецепт и снова отправьте на модерацию — кнопка «Отправить на модерацию» в редакторе.

@component('mail::button', ['url' => $url, 'color' => 'success'])
Открыть рецепт
@endcomponent
@endif

С уважением,<br>
команда «{{ config('app.name') }}»
@endcomponent
