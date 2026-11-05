// Validación al salir de cada campo (blur). Se limpia al volver a escribir.
// El servidor vuelve a validar todo al enviar.
(() => {
    const CLAVE_NUEVA = /^(?=.*\p{Ll})(?=.*\p{Lu})(?=.*\p{N})(?=.*[^\p{L}\p{N}]).{8,}$/u;
    const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    function errorDe(campo) {
        const valor = campo.value.trim();
        if (campo.required && valor === '') {
            return 'Completá este campo.';
        }
        if (campo.type === 'email' && valor !== '' && !EMAIL.test(valor)) {
            return 'Ingresá un email válido.';
        }
        if (campo.hasAttribute('data-clave-nueva') && valor !== '' && !CLAVE_NUEVA.test(valor)) {
            return 'La clave necesita 8 caracteres, con mayúscula, minúscula, número y símbolo.';
        }
        return '';
    }

    function mostrar(campo, texto) {
        let aviso = document.getElementById(campo.name + '-error');
        if (!aviso) {
            aviso = document.createElement('small');
            aviso.className = 'error-campo';
            aviso.id = campo.name + '-error';
            campo.insertAdjacentElement('afterend', aviso);
            campo.setAttribute('aria-describedby', aviso.id);
        }
        aviso.textContent = texto;
        aviso.hidden = texto === '';
        campo.setAttribute('aria-invalid', texto === '' ? 'false' : 'true');
    }

    document.querySelectorAll('form.formulario input[required], form.formulario input[type="email"]').forEach((campo) => {
        campo.addEventListener('blur', () => mostrar(campo, errorDe(campo)));
        campo.addEventListener('input', () => {
            if (campo.getAttribute('aria-invalid') === 'true') {
                mostrar(campo, '');
            }
        });
    });
})();
