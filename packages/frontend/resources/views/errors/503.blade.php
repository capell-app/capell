@extends('errors::minimal')

@section('title', __('capell-frontend::errors.maintenance_title'))
@section('code', '503')
@section('message', __('capell-frontend::errors.maintenance_message'))
@section('headline', __('capell-frontend::errors.maintenance_headline'))
@section('description', __('capell-frontend::errors.maintenance_description'))
