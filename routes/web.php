<?php

use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::library')->name('library');
Route::livewire('/search', 'pages::search')->name('search');
Route::livewire('/recently-added', 'pages::recently-added')->name('recentlyAdded');
Route::livewire('/recently-played', 'pages::recently-played')->name('recentlyPlayed');
Route::livewire('/playlist/{playlist}', 'pages::playlist-detail')->name('playlist');
Route::livewire('/settings', 'pages::settings')->name('settings');
