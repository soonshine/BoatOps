<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>@yield('title') · BoatOps</title>
<style>
body { display: grid; min-height: 100vh; place-items: center; margin: 0; padding: 1.5rem; background: #f4f7fb; color: #132238; font-family: system-ui, sans-serif; }
main { width: min(100%, 34rem); padding: 2rem; border: 1px solid #d8e2ec; border-radius: 1rem; background: #fff; box-shadow: 0 10px 28px rgb(15 23 42 / 8%); }
p { color: #52667a; line-height: 1.7; }
.code { color: #075985; font-weight: 800; }
</style>
</head>
<body>
<main>
<p class="code">错误 @yield('code')</p>
<h1>@yield('title')</h1>
<p>@yield('message')</p>
</main>
</body>
</html>
