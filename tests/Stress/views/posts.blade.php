@foreach ($posts as $post)
<li>{{ $post->author->name }}</li>
@endforeach
