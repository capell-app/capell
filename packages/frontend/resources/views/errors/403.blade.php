@extends('errors::minimal')

@section('title', __('capell-frontend::errors.forbidden_title'))
@section('code', '403')
@section('message', $exception->getMessage() ?: __('capell-frontend::errors.forbidden_message'))
@section('headline', __('capell-frontend::errors.forbidden_headline'))
@section('description', __('capell-frontend::errors.forbidden_description'))
