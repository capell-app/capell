@extends('errors::minimal')

@section('title', __('Forbidden'))
@section('code', '403')
@section('message', __($exception->getMessage() ?: 'Forbidden'))
@section('headline', __('You don’t have access to this page'))
@section('description', __('You don’t have permission to view this page. If you think this is a mistake, please contact your administrator.'))
