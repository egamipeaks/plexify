<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::library')->name('library');
Route::livewire('/search', 'pages::search')->name('search');
Route::livewire('/playlist/{playlist}', 'pages::playlist-detail')->name('playlist');
Route::livewire('/settings', 'pages::settings')->name('settings');
