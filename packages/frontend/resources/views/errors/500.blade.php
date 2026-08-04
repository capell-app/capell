@extends('errors::minimal')

@section('title', __('capell-frontend::errors.server_error_title'))
@section('code', '500')
@section('message', __('capell-frontend::errors.server_error_message'))
@section('headline', __('capell-frontend::errors.server_error_headline'))
@section('description', __('capell-frontend::errors.server_error_description'))
