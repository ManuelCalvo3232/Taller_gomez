// Marca y modelo de vehículo, compartido por "Pedir turno" y "Mis vehículos".
// El catálogo llega desde el servidor. Las listas se arman según el tipo y la marca elegidos.
window.Vehiculo = (() => {
    const AYUDA = {
        auto: 'Auto: AB123CD o ABC123',
        moto: 'Moto: A123BCD o 123BCD',
    };

    function iniciar(form, catalogo, inicial = {}) {
        const q = (selector) => form.querySelector(selector);
        const marcaSel = q('[name="marca_id"]');
        const modeloSel = q('[name="modelo_id"]');
        const wrapModelo = q('[data-wrap="modelo"]');
        const wrapMarcaOtra = q('[data-wrap="marca-otra"]');
        const wrapModeloOtro = q('[data-wrap="modelo-otro"]');
        const ayuda = q('#ayuda-patente');

        const tipoActual = () => form.querySelector('input[name="tipo"]:checked').value;

        function opcion(valor, texto) {
            const o = document.createElement('option');
            o.value = valor;
            o.textContent = texto;
            return o;
        }

        // Solo se ofrecen las marcas que tienen modelos del tipo elegido
        function llenarMarcas(seleccion) {
            const tipo = tipoActual();
            const conModelo = new Set(catalogo.modelos.filter((m) => m.tipo === tipo).map((m) => m.marca));
            marcaSel.innerHTML = '';
            marcaSel.appendChild(opcion('', 'Elegí una marca'));
            catalogo.marcas
                .filter((m) => conModelo.has(m.id))
                .forEach((m) => marcaSel.appendChild(opcion(String(m.id), m.nombre)));
            marcaSel.appendChild(opcion('otra', 'Otra marca'));
            marcaSel.value = seleccion;
        }

        function llenarModelos(seleccion) {
            const marca = marcaSel.value;
            const tipo = tipoActual();
            modeloSel.innerHTML = '';
            modeloSel.appendChild(opcion('', 'Elegí un modelo'));
            if (marca && marca !== 'otra') {
                catalogo.modelos
                    .filter((m) => m.marca === Number(marca) && m.tipo === tipo)
                    .forEach((m) => modeloSel.appendChild(opcion(String(m.id), m.nombre)));
            }
            modeloSel.appendChild(opcion('otro', 'Otro modelo (especificar)'));
            modeloSel.value = seleccion;
        }

        // Muestra u oculta los campos libres según lo elegido
        function refrescar() {
            const marcaEsOtra = marcaSel.value === 'otra';
            if (marcaEsOtra) {
                modeloSel.value = 'otro';
            }
            wrapMarcaOtra.hidden = !marcaEsOtra;
            wrapModelo.hidden = marcaEsOtra;
            wrapModeloOtro.hidden = !(marcaEsOtra || modeloSel.value === 'otro');
            ayuda.textContent = AYUDA[tipoActual()];
            form.querySelectorAll('[data-solo]').forEach((bloque) => {
                bloque.hidden = bloque.dataset.solo !== tipoActual();
            });
        }

        function alCambiarTipo() {
            llenarMarcas('');
            llenarModelos('');
            refrescar();
        }

        form.querySelectorAll('input[name="tipo"]').forEach((radio) => {
            radio.addEventListener('change', alCambiarTipo);
        });
        marcaSel.addEventListener('change', () => {
            llenarModelos('');
            refrescar();
        });
        modeloSel.addEventListener('change', refrescar);

        // Carga un vehículo (inicial o guardado) en el formulario
        function aplicar(v) {
            const tipo = v.tipo === 'moto' ? 'moto' : 'auto';
            form.querySelector(`input[name="tipo"][value="${tipo}"]`).checked = true;
            llenarMarcas(v.marca_id ?? '');
            llenarModelos(v.modelo_id ?? '');
            ['patente', 'marca_otra', 'modelo_otro', 'anio', 'kilometros', 'cilindrada'].forEach((campo) => {
                const input = q(`[name="${campo}"]`);
                if (input) {
                    input.value = v[campo] ?? '';
                }
            });
            refrescar();
        }

        aplicar(inicial);
        return { aplicar };
    }

    return { iniciar };
})();