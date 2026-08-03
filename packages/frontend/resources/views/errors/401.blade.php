@extends('errors::minimal')

@section('title', __('capell-frontend::errors.unauthorized_title'))
@section('code', '401')
@section('message', __('capell-frontend::errors.unauthorized_message'))
@section('headline', __('capell-frontend::errors.unauthorized_headline'))
@section('description', __('capell-frontend::errors.unauthorized_description'))
