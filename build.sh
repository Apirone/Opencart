#!/bin/bash
ROOT_PATH=$1
VER=$2
TAG=$3
SRC_PATH=${ROOT_PATH}
BUILD_PATH="${ROOT_PATH}/build"
ARC_PATH=${ROOT_PATH}/apirone-crypto-payments.oc${VER}.${TAG}.ocmod.zip

rm -rf ${BUILD_PATH}

if [[ ${VER} < 4 ]]; then
    DST_PATH="${BUILD_PATH}/upload"
else
    DST_PATH="${BUILD_PATH}"
fi

paths=( $(grep -v '#' ${SRC_PATH}/v${VER}.map) )

src=""
for val in ${paths[@]}
do
    if [[ $src == "" ]]; then
        src=${SRC_PATH}/${val}
    else
        dst=${DST_PATH}/${val}
        mkdir -p `echo ${dst} | sed s/\\\/[^\\\/]*$//`
        cp -R ${src} ${dst}

        src=""
    fi
done

cd "${BUILD_PATH}"
zip -rq "${ARC_PATH}" ./*
