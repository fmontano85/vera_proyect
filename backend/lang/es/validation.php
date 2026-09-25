<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Mensajes de validacion en espanol (es-SV)
|--------------------------------------------------------------------------
|
| La interfaz de VERA es en espanol (seccion 6 del CLAUDE.md raiz) y el
| frontend muestra estos mensajes tal cual. Escrito a mano, sin paquete de
| traducciones (seccion 7: no agregar dependencias sin preguntar). Cubre
| las reglas que usa la app hoy; si se usa una regla nueva, agregarla aqui
| o el usuario vera la clave cruda ('validation.xxx').
|
*/

return [
    'array' => 'El campo :attribute debe ser una lista.',
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'date' => 'El campo :attribute no es una fecha válida.',
    'distinct' => 'El campo :attribute tiene un valor repetido.',
    'email' => 'El campo :attribute debe ser un correo electrónico válido.',
    'exists' => 'El :attribute seleccionado no es válido.',
    'file' => 'El campo :attribute debe ser un archivo.',
    'in' => 'El valor de :attribute no es válido.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'max' => [
        'array' => 'El campo :attribute no puede tener más de :max elementos.',
        'file' => 'El archivo :attribute no puede pesar más de :max kilobytes.',
        'numeric' => 'El campo :attribute no puede ser mayor que :max.',
        'string' => 'El campo :attribute no puede tener más de :max caracteres.',
    ],
    'mimes' => 'El archivo :attribute debe ser de tipo: :values.',
    'min' => [
        'array' => 'El campo :attribute debe tener al menos :min elementos.',
        'file' => 'El archivo :attribute debe pesar al menos :min kilobytes.',
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
    ],
    'numeric' => 'El campo :attribute debe ser un número.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_if' => 'El campo :attribute es obligatorio.',
    'string' => 'El campo :attribute debe ser texto.',
    'unique' => 'Ese :attribute ya existe.',
    'uploaded' => 'No se pudo subir el archivo :attribute.',

    'custom' => [],

    /*
    | Nombres legibles de los campos (los que ve el usuario en el mensaje).
    */
    'attributes' => [
        'activo' => 'estado',
        'aliases' => 'aliases',
        'aliases.*' => 'alias',
        'alto' => 'riesgo alto',
        'bajo' => 'riesgo bajo',
        'bandeja' => 'bandeja',
        'buscar' => 'búsqueda',
        'delitos' => 'delitos',
        'delitos.*' => 'delito',
        'dias_atras' => 'días atrás',
        'documento' => 'documento',
        'email' => 'correo electrónico',
        'estado' => 'estado',
        'estado_resolucion' => 'resolución',
        'fecha_hecho' => 'fecha del hecho',
        'filtro' => 'filtro',
        'frecuencia_seguimiento_dias' => 'frecuencia de seguimiento',
        'medio' => 'riesgo medio',
        'nivel' => 'nivel de riesgo',
        'nivel_riesgo' => 'nivel de riesgo',
        'nombre' => 'alias',
        'nombre_canonico' => 'nombre completo',
        'nombre_como_aparece' => 'nombre como aparece',
        'observacion' => 'observación',
        'password' => 'contraseña',
        'pdf' => 'PDF',
        'resumen' => 'resumen',
        'rol' => 'rol',
        'sin_nivel' => 'sin nivel asignado',
        'subject_id' => 'persona vigilada',
        'tag_ids' => 'tags',
        'tag_ids.*' => 'tag',
        'tipo' => 'tipo',
    ],
];
