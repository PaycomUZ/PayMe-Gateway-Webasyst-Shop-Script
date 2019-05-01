<?php

return array(
    'merchant_idd' => array(
        'value'        => '',
        'title'        => ('ID поставщика'),
        'description'  => 'Указан в Личном кабинете https://merchant.paycom.uz/#/login',
        'control_type' => waHtmlControl::INPUT,
    ),
    'merchant_password' => array(
        'value'        => '',
        'title'        => ('Ключ - пароль кассы'),
        'description'  => 'Указан в Личном кабинете https://merchant.paycom.uz/#/login',
        'control_type' => waHtmlControl::INPUT,
    ),
	'merchant_password_for_test' => array(
        'value'        => '',
        'title'        => ('Ключ - пароль кассы для теста'),
        'description'  => 'Указан в Личном кабинете https://merchant.paycom.uz/#/login',
        'control_type' => waHtmlControl::INPUT,
    ),
	'checkout_url' 	   => array(
        'value'        => 'https://checkout.paycom.uz',
        'title'        => ('Checkout URL'),
        'description'  => '',
        'control_type' => waHtmlControl::INPUT,
    ),
	'checkout_url_test'=> array(
        'value'        => 'https://test.paycom.uz',
        'title'        => ('Checkout URL для теста'),
        'description'  => '',
        'control_type' => waHtmlControl::INPUT,
    ),
	'test_mode'    => array(
        'value'        => true,
        'title'        => 'Тестовый режим',
        'description'  => '',
        'control_type' => waHtmlControl::CHECKBOX,
    ), 
	'product_information' => array(
        'value'        => 'yes',
        'title'        => 'Добавить в чек данные о товарах',
        'description'  => '',
        'control_type' => waHtmlControl::SELECT,
        'options'      => array(
            'yes' => 'Да',
            'no'  => 'Нет'
        )
    ),
	'return_after_payment' => array(
        'value'        => '0',
        'title'        => 'Вернуться после оплаты через',
        'description'  => '',
        'control_type' => waHtmlControl::SELECT,
        'options'      => array(
            '0'     => 'Моментально',
			'15000' => '15 секунд',
			'30000' => '30 секунд',
            '60000' => '60 секунд'
        )
    ),
    'payment_language' => array(
        'value'        => 'ru',
        'title'        => 'Язык платежной формы',
        'description'  => 'Выберите язык платежной формы для Вашего магазина',
        'control_type' => waHtmlControl::SELECT,
        'options'      => array(
            'ru' => 'Русский',
            'en' => 'Английский'
        )
    )
);