@extends('admin.layout')

@section('content')
    <h2 class="secao">Entrar</h2>
    <div class="cartao" style="max-width:380px">
        <p class="mut pequeno">Acesso restrito à equipe. As contas são criadas por <code>artisan fertways:admin</code>.</p>
        <form method="POST" action="{{ route('admin.login') }}">
            @csrf
            <div style="margin-bottom:10px">
                <label class="pequeno mut">E-mail</label>
                <input type="email" name="email" value="{{ old('email') }}" autofocus required>
            </div>
            <div style="margin-bottom:10px">
                <label class="pequeno mut">Senha</label>
                <input type="password" name="password" required>
            </div>
            <label class="pequeno mut" style="display:block;margin-bottom:10px">
                <input type="checkbox" name="remember" value="1" style="width:auto"> Manter conectado
            </label>
            <button type="submit">Entrar</button>
        </form>
    </div>
@endsection
