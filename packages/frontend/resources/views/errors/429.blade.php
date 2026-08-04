@extends('errors::minimal')

@section('title', __('capell-frontend::errors.too_many_requests_title'))
@section('code', '429')
@section('message', __('capell-frontend::errors.too_many_requests_message'))
@section('headline', __('capell-frontend::errors.too_many_requests_headline'))
@section('description', __('capell-frontend::errors.too_many_requests_description'))
