<?php

// Сообщения только для правил, которые используются в системе.
return [
    'required' => 'Поле ":attribute" обязательно.',
    'string' => 'Поле ":attribute" должно быть строкой.',
    'max' => ['string' => 'Поле ":attribute" не длиннее :max символов.', 'numeric' => 'Поле ":attribute" не больше :max.'],
    'date' => 'Поле ":attribute" – не дата.',
    'email' => 'Поле ":attribute" – неверный адрес почты.',
    'digits' => 'Поле ":attribute" – ровно :digits цифр.',
    'unique' => 'Такое значение поля ":attribute" уже есть.',
    'exists' => 'Выбранное значение поля ":attribute" не найдено.',
    'enum' => 'Недопустимое значение поля ":attribute".',

    'attributes' => [
        'type' => 'Тип',
        'status' => 'Статус',
        'inv_number' => 'Инвентарный номер',
        'serial' => 'Серийный номер',
        'vendor' => 'Производитель',
        'model' => 'Модель',
        'hostname' => 'Сетевое имя',
        'purchased_at' => 'Дата покупки',
        'warranty_until' => 'Гарантия до',
        'notes' => 'Заметки',
        'office_id' => 'Филиал',
        'location_id' => 'Место',
        'employee_id' => 'Сотрудник',
        'comment' => 'Комментарий',
        'kind' => 'Вид',
        'name' => 'Название',
        'parent_id' => 'Внутри',
        'iin' => 'ИИН',
        'role' => 'Роль',
    ],
];
