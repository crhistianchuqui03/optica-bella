/**
 * Formulario de inicio de sesión.
 * Se extrajo de login.html para cumplir la CSP estricta del proyecto.
 */
(function () {
    const form = document.getElementById('login-form');
    const errorBox = document.getElementById('login-error');
    const btn = document.getElementById('login-btn');
    const usernameInput = document.getElementById('username');

    function mostrarError(mensaje) {
        errorBox.textContent = mensaje;
        errorBox.hidden = false;
    }

    function restaurarBoton() {
        btn.disabled = false;
        btn.textContent = 'Ingresar';
    }

    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        errorBox.hidden = true;

        const username = usernameInput.value.trim();
        const password = document.getElementById('password').value;

        if (username === '' || password === '') {
            mostrarError('Ingrese usuario y contraseña.');
            usernameInput.focus();
            return;
        }

        btn.disabled = true;
        btn.textContent = 'Ingresando...';

        try {
            const res = await API.post(
                'api/auth/login.php',
                { username: username, password: password },
                { redirectOnUnauthorized: false }
            );

            if (res.status === 'success') {
                window.location.href = 'index.html';
                return;
            }

            mostrarError(res.message || 'No se pudo iniciar sesión.');
        } catch (error) {
            mostrarError(error.message || 'Error de conexión con el servidor.');
        }

        restaurarBoton();
        usernameInput.focus();
    });
})();