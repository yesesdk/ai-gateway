/* AI Gateway 前台交互 */
(function () {
    'use strict';

    /* 渠道测试 */
    document.querySelectorAll('.btn-test').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = btn.getAttribute('data-id');
            var box = document.getElementById('test-result');
            var old = btn.innerHTML;
            btn.innerHTML = '测试中…';
            btn.disabled = true;

            var fd = new FormData();
            fd.append('action', 'channel_test');
            fd.append('id', id);

            fetch('index.php', { method: 'POST', body: fd })
                .then(function (r) { return r.json(); })
                .then(function (j) {
                    if (j.results && j.results.length) {
                        // 多地址逐个显示
                        j.results.forEach(function (res) {
                            var div = document.createElement('div');
                            div.className = 'test-item ' + (res.ok ? 'ok' : 'err');
                            div.textContent = (res.ok ? '✅' : '❌') + ' ' + res.url + ' — ' + res.msg;
                            box.appendChild(div);
                        });
                        if (j.models && j.models.length) {
                            var div = document.createElement('div');
                            div.className = 'test-item ok';
                            div.textContent = '🧠 上游模型: ' + j.models.join(', ') + (j.models.length >= 10 ? ' …' : '');
                            box.appendChild(div);
                        }
                    } else {
                        var div = document.createElement('div');
                        div.className = 'test-item ' + (j.ok ? 'ok' : 'err');
                        div.textContent = (j.msg || '') + (j.status ? ' (HTTP ' + j.status + ')' : '');
                        box.appendChild(div);
                    }
                })
                .catch(function () {
                    var div = document.createElement('div');
                    div.className = 'test-item err';
                    div.textContent = '测试请求失败，请检查网络或渠道配置';
                    box.prepend(div);
                })
                .finally(function () {
                    btn.innerHTML = old;
                    btn.disabled = false;
                });
        });
    });

    /* 复制密钥 */
    document.querySelectorAll('.btn-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var full = btn.getAttribute('data-full');
            function done() {
                btn.textContent = '✅';
                setTimeout(function () { btn.textContent = '📋'; }, 1200);
            }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(full).then(done, done);
            } else {
                var ta = document.createElement('textarea');
                ta.value = full;
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
                done();
            }
        });
    });
})();
